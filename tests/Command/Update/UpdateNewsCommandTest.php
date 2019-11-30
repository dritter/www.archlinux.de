<?php

namespace App\Tests\Command\Update;

use App\Command\Exception\ValidationException;
use App\Command\Update\UpdateNewsCommand;
use App\Entity\NewsAuthor;
use App\Entity\NewsItem;
use App\Repository\NewsItemRepository;
use App\Service\NewsItemFetcher;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * @covers \App\Command\Update\UpdateNewsCommand
 */
class UpdateNewsCommandTest extends KernelTestCase
{
    public function testCommand()
    {
        $newNewsItem = (new NewsItem('2'))->setLastModified(new \DateTime('-1 day'));
        $oldNewsItem = (new NewsItem('1'))->setLastModified(new \DateTime('-2 day'));

        /** @var NewsItemRepository|MockObject $newsItemRepository */
        $newsItemRepository = $this->createMock(NewsItemRepository::class);
        $newsItemRepository->method('findAllExceptByIdsNewerThan')->willReturn([$oldNewsItem]);

        /** @var EntityManagerInterface|MockObject $entityManager */
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->once())->method('persist')->with($newNewsItem);
        $entityManager->expects($this->once())->method('remove')->with($oldNewsItem);
        $entityManager->expects($this->once())->method('flush');

        /** @var NewsItemFetcher|MockObject $newsItemFetcher */
        $newsItemFetcher = $this->createMock(NewsItemFetcher::class);
        $newsItemFetcher->method('getIterator')->willReturn(new \ArrayIterator([$newNewsItem]));

        /** @var ValidatorInterface|MockObject $validator */
        $validator = $this->createMock(ValidatorInterface::class);
        $validator->expects($this->atLeastOnce())->method('validate')->willReturn(new ConstraintViolationList());

        $kernel = self::bootKernel();
        $application = new Application($kernel);

        $application->add(new UpdateNewsCommand($entityManager, $newsItemFetcher, $newsItemRepository, $validator));

        $command = $application->find('app:update:news');
        $commandTester = new CommandTester($command);
        $commandTester->execute(['command' => $command->getName()]);

        $this->assertEquals(0, $commandTester->getStatusCode());
    }

    public function testArchivedItemsAreKept()
    {
        $newNewsItem = (new NewsItem('2'))->setLastModified(new \DateTime('-1 day'));
        $oldNewsItem = (new NewsItem('1'))->setLastModified(new \DateTime('-2 day'));

        /** @var NewsItemRepository|MockObject $newsItemRepository */
        $newsItemRepository = $this->createMock(NewsItemRepository::class);
        $newsItemRepository
            ->method('findAllExceptByIdsNewerThan')
            ->with([$oldNewsItem->getId(), $newNewsItem->getId()], $oldNewsItem->getLastModified())
            ->willReturn([]);

        /** @var EntityManagerInterface|MockObject $entityManager */
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->atLeastOnce())->method('persist');
        $entityManager->expects($this->never())->method('remove');
        $entityManager->expects($this->once())->method('flush');

        /** @var NewsItemFetcher|MockObject $newsItemFetcher */
        $newsItemFetcher = $this->createMock(NewsItemFetcher::class);
        $newsItemFetcher->method('getIterator')->willReturn(new \ArrayIterator([$oldNewsItem, $newNewsItem]));

        /** @var ValidatorInterface|MockObject $validator */
        $validator = $this->createMock(ValidatorInterface::class);
        $validator->expects($this->atLeastOnce())->method('validate')->willReturn(new ConstraintViolationList());

        $kernel = self::bootKernel();
        $application = new Application($kernel);

        $application->add(new UpdateNewsCommand($entityManager, $newsItemFetcher, $newsItemRepository, $validator));

        $command = $application->find('app:update:news');
        $commandTester = new CommandTester($command);
        $commandTester->execute(['command' => $command->getName()]);

        $this->assertEquals(0, $commandTester->getStatusCode());
    }

    public function testUpdateFailsOnInvalidItems()
    {
        /** @var NewsItemRepository|MockObject $newsItemRepository */
        $newsItemRepository = $this->createMock(NewsItemRepository::class);

        /** @var EntityManagerInterface|MockObject $entityManager */
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->never())->method('flush');

        /** @var NewsItemFetcher|MockObject $newsItemFetcher */
        $newsItemFetcher = $this->createMock(NewsItemFetcher::class);
        $newsItemFetcher->method('getIterator')->willReturn(new \ArrayIterator([new NewsItem('2')]));

        /** @var ValidatorInterface|MockObject $validator */
        $validator = $this->createMock(ValidatorInterface::class);
        $validator
            ->expects($this->atLeastOnce())
            ->method('validate')
            ->willReturn(new ConstraintViolationList([$this->createMock(ConstraintViolation::class)]));

        $kernel = self::bootKernel();
        $application = new Application($kernel);

        $application->add(new UpdateNewsCommand($entityManager, $newsItemFetcher, $newsItemRepository, $validator));

        $command = $application->find('app:update:news');
        $commandTester = new CommandTester($command);

        $this->expectException(ValidationException::class);
        $commandTester->execute(['command' => $command->getName()]);
    }

    public function testUpdateNews()
    {
        $newsItem = (new NewsItem('2'))
            ->setLastModified(new \DateTime('-1 day'))
            ->setDescription('')
            ->setLink('')
            ->setSlug('')
            ->setTitle('')
            ->setAuthor(new NewsAuthor());

        /** @var NewsItemRepository|MockObject $newsItemRepository */
        $newsItemRepository = $this->createMock(NewsItemRepository::class);
        $newsItemRepository->expects($this->once())->method('find')->with($newsItem->getId())->willReturn($newsItem);

        /** @var EntityManagerInterface|MockObject $entityManager */
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->once())->method('persist')->with($newsItem);
        $entityManager->expects($this->once())->method('flush');

        /** @var NewsItemFetcher|MockObject $newsItemFetcher */
        $newsItemFetcher = $this->createMock(NewsItemFetcher::class);
        $newsItemFetcher->method('getIterator')->willReturn(new \ArrayIterator([$newsItem]));

        /** @var ValidatorInterface|MockObject $validator */
        $validator = $this->createMock(ValidatorInterface::class);
        $validator->expects($this->atLeastOnce())->method('validate')->willReturn(new ConstraintViolationList());

        $kernel = self::bootKernel();
        $application = new Application($kernel);

        $application->add(new UpdateNewsCommand($entityManager, $newsItemFetcher, $newsItemRepository, $validator));

        $command = $application->find('app:update:news');
        $commandTester = new CommandTester($command);
        $commandTester->execute(['command' => $command->getName()]);

        $this->assertEquals(0, $commandTester->getStatusCode());
    }
}
