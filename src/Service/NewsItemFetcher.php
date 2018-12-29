<?php

namespace App\Service;

use App\Entity\NewsAuthor;
use App\Entity\NewsItem;
use FeedIo\Feed\ItemInterface;
use FeedIo\FeedInterface;
use FeedIo\FeedIo;

class NewsItemFetcher implements \IteratorAggregate
{
    /** @var FeedIo */
    private $feedIo;

    /** @var string */
    private $newsFeedUrl;

    /**
     * @param FeedIo $feedIo
     * @param string $newsFeedUrl
     */
    public function __construct(FeedIo $feedIo, string $newsFeedUrl)
    {
        $this->feedIo = $feedIo;
        $this->newsFeedUrl = $newsFeedUrl;
    }

    /**
     * @return iterable
     */
    public function getIterator(): iterable
    {
        /** @var ItemInterface $newsEntry */
        foreach ($this->fetchNewsFeed() as $newsEntry) {
            $newsItem = new NewsItem($newsEntry->getPublicId());
            $newsItem
                ->setTitle($newsEntry->getTitle())
                ->setLink($newsEntry->getLink())
                ->setDescription($newsEntry->getDescription())
                ->setAuthor(
                    (new NewsAuthor())
                        ->setUri($newsEntry->getAuthor()->getUri())
                        ->setName($newsEntry->getAuthor()->getName())
                )
                ->setLastModified($newsEntry->getLastModified());
            $newsItem->setSlug($this->createSlug($newsItem));
            yield $newsItem;
        }
    }

    /**
     * @return FeedInterface
     */
    private function fetchNewsFeed(): FeedInterface
    {
        $feed = $this->feedIo->read($this->newsFeedUrl)->getFeed();
        if ($feed->count() == 0) {
            throw new \RuntimeException('empty news feed');
        }
        return $feed;
    }

    /**
     * @param NewsItem $newsItem
     * @return string
     */
    private function createSlug(NewsItem $newsItem): string
    {
        setlocale(LC_ALL, 'de_DE.UTF-8');
        return substr(
            $this->parseId($newsItem->getId()) . '-' . trim(
                preg_replace(
                    ['/[^a-z0-9_\-\.]+/', '/\-+/'],
                    '-',
                    strtolower(
                        iconv(
                            'UTF-8',
                            'ASCII//TRANSLIT',
                            $newsItem->getTitle()
                        )
                    )
                ),
                '_-.'
            ),
            0,
            255
        );
    }

    /**
     * @param string $id
     * @return int
     */
    private function parseId(string $id): int
    {
        if (preg_match('/id=(\d+)/', $id, $matches) !== false) {
            return $matches[1];
        }
        return crc32($id);
    }
}
