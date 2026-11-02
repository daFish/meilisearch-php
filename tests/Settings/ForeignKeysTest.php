<?php

declare(strict_types=1);

namespace Tests\Settings;

use Meilisearch\Contracts\TaskDetails\SettingsUpdateDetails;
use Meilisearch\Contracts\TaskStatus;
use Meilisearch\Endpoints\Index;
use Meilisearch\Http\Client;
use Tests\TestCase;

final class ForeignKeysTest extends TestCase
{
    private Index $index;

    protected function setUp(): void
    {
        parent::setUp();

        $this->toggleForeignKeysFeature(true);
        $this->index = $this->createEmptyIndex($this->safeIndexName());
    }

    // @phpstan-ignore phpunit.callParent (parent::tearDown() is called inside try/finally)
    protected function tearDown(): void
    {
        try {
            parent::tearDown();
        } finally {
            $this->toggleForeignKeysFeature(false);
        }
    }

    public function testGetDefaultForeignKeys(): void
    {
        self::assertSame([], $this->index->getForeignKeys());
    }

    public function testUpdateForeignKeys(): void
    {
        $authorsIndex = $this->createEmptyIndex($this->safeIndexName('authors'));
        $foreignKeys = [
            ['foreignIndexUid' => $authorsIndex->getUid(), 'fieldName' => 'author'],
            ['foreignIndexUid' => $authorsIndex->getUid(), 'fieldName' => 'related_authors'],
        ];

        $task = $this->index->updateForeignKeys($foreignKeys)->wait();

        self::assertSame(TaskStatus::Succeeded, $task->getStatus());
        self::assertSame($foreignKeys, $this->index->getForeignKeys());
    }

    public function testResetForeignKeys(): void
    {
        $authorsIndex = $this->createEmptyIndex($this->safeIndexName('authors'));
        $this->index->updateForeignKeys([
            ['foreignIndexUid' => $authorsIndex->getUid(), 'fieldName' => 'author'],
        ])->wait();

        $task = $this->index->resetForeignKeys()->wait();

        self::assertSame(TaskStatus::Succeeded, $task->getStatus());
        self::assertSame([], $this->index->getForeignKeys());
    }

    public function testUpdateForeignKeysTaskDetailsContainSubmittedForeignKeys(): void
    {
        $authorsIndex = $this->createEmptyIndex($this->safeIndexName('authors'));
        $foreignKeys = [
            ['foreignIndexUid' => $authorsIndex->getUid(), 'fieldName' => 'author'],
        ];

        $task = $this->index->updateForeignKeys($foreignKeys)->wait();

        self::assertInstanceOf(SettingsUpdateDetails::class, $details = $task->getDetails());
        self::assertSame($foreignKeys, $details->foreignKeys);
    }

    public function testResetForeignKeysTaskDetailsContainNullForeignKeys(): void
    {
        $authorsIndex = $this->createEmptyIndex($this->safeIndexName('authors'));
        $this->index->updateForeignKeys([
            ['foreignIndexUid' => $authorsIndex->getUid(), 'fieldName' => 'author'],
        ])->wait();

        $task = $this->index->resetForeignKeys()->wait();

        self::assertInstanceOf(SettingsUpdateDetails::class, $details = $task->getDetails());
        self::assertNull($details->foreignKeys);
    }

    public function testGetSettingsIncludesDefaultForeignKeys(): void
    {
        self::assertSame([], $this->index->getSettings()->toArray()['foreignKeys']);
    }

    public function testUpdateSettingsWithForeignKeys(): void
    {
        $authorsIndex = $this->createEmptyIndex($this->safeIndexName('authors'));
        $foreignKeys = [
            ['foreignIndexUid' => $authorsIndex->getUid(), 'fieldName' => 'author'],
        ];

        $task = $this->index->updateSettings(['foreignKeys' => $foreignKeys])->wait();

        self::assertSame(TaskStatus::Succeeded, $task->getStatus());
        self::assertSame($foreignKeys, $this->index->getSettings()->toArray()['foreignKeys']);
        self::assertSame($foreignKeys, $this->index->getForeignKeys());
    }

    public function testSearchHydratesForeignDocuments(): void
    {
        $this->createBooksReferencingAuthors();

        $hits = $this->index->search('Emma')->getHits();

        self::assertCount(1, $hits);
        self::assertSame('Emma', $hits[0]['title']);
        self::assertSame(['id' => 1, 'name' => 'Jane Austen', 'country' => 'UK'], $hits[0]['author']);
    }

    public function testSearchFiltersOnForeignDocumentFields(): void
    {
        $this->createBooksReferencingAuthors();

        $hits = $this->index->search('', ['filter' => '_foreign(author, country = "UK")'])->getHits();

        self::assertEqualsCanonicalizing(['Emma', 'Pride and Prejudice'], array_column($hits, 'title'));
    }

    private function createBooksReferencingAuthors(): void
    {
        $authorsIndex = $this->createEmptyIndex($this->safeIndexName('authors'));
        $authorsIndex->addDocuments([
            ['id' => 1, 'name' => 'Jane Austen', 'country' => 'UK'],
            ['id' => 2, 'name' => 'Mark Twain', 'country' => 'USA'],
        ])->wait();
        $authorsIndex->updateFilterableAttributes(['country'])->wait();

        $this->index->addDocuments([
            ['id' => 1, 'title' => 'Emma', 'author' => 1],
            ['id' => 2, 'title' => 'The Adventures of Tom Sawyer', 'author' => 2],
            ['id' => 3, 'title' => 'Pride and Prejudice', 'author' => 1],
        ])->wait();
        $this->index->updateForeignKeys([
            ['foreignIndexUid' => $authorsIndex->getUid(), 'fieldName' => 'author'],
        ])->wait();
        $this->index->updateFilterableAttributes(['author'])->wait();
    }

    private function toggleForeignKeysFeature(bool $enabled): void
    {
        $apiKey = getenv('MEILISEARCH_API_KEY');
        if (!$apiKey) {
            throw new \Exception('Missing `MEILISEARCH_API_KEY` environment variable');
        }

        $http = new Client($this->host, $apiKey);
        $http->patch('/experimental-features', ['foreignKeys' => $enabled]);
    }
}
