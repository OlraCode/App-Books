<?php

namespace App\Tests\Controller;

use App\Entity\Book;
use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class BookControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $manager;
    private EntityRepository $repository;
    private string $path = '/book/';

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->manager = static::getContainer()->get('doctrine')->getManager();
        $this->repository = $this->manager->getRepository(Book::class);

        foreach ($this->repository->findAll() as $object) {
            $this->manager->remove($object);
        }
        foreach ($this->manager->getRepository(User::class)->findAll() as $object) {
            $this->manager->remove($object);
        }

        $this->manager->flush();

        $hash = $this->getContainer()->get(UserPasswordHasherInterface::class);

        $unverify = new User;
        $unverify->setEmail('unverify@example.com');
        $unverify->setPassword('unverify1234');
        $unverify->setVerified(false);

        $passwordHash = $hash->hashPassword($unverify, $unverify->getPassword());
        $unverify->setPassword($passwordHash);

        $user = new User;
        $user->setEmail('user@example.com');
        $user->setPassword('user1234');
        $user->setVerified(true);

        $passwordHash = $hash->hashPassword($user, $user->getPassword());
        $user->setPassword($passwordHash);

        $admin = new User;
        $admin->setEmail('admin@example.com');
        $admin->setPassword('admin1234');
        $admin->setVerified(true);
        $admin->setRoles(['ROLE_ADMIN']);

        $passwordHash = $hash->hashPassword($admin, $admin->getPassword());
        $admin->setPassword($passwordHash);

        $this->manager->persist($unverify);
        $this->manager->persist($user);
        $this->manager->persist($admin);

        $this->manager->flush();

        $this->client->loginUser($admin);
    }

    public function testIndex(): void
    {
        $this->client->followRedirects();
        $crawler = $this->client->request('GET', $this->path);

        self::assertResponseStatusCodeSame(200);
        self::assertPageTitleContains('Lista de Livros');
    }

    public function testOnlyAdminCanAddBooks(): void
    {
        $userRepository = static::getContainer()->get(UserRepository::class);
        $user = $userRepository->findOneByEmail('user@example.com');
        $this->client->loginUser($user);

        $this->client->followRedirects();
        $crawler = $this->client->request('GET', $this->path);

        $element = $crawler->filter('#add-book');

        self::assertCount(0, $element);

        $this->client->request('GET', sprintf('%snew', $this->path));
        self::assertResponseStatusCodeSame(403);
    }

    public function testNew(): void
    {
        $this->client->request('GET', sprintf('%snew', $this->path));

        self::assertResponseStatusCodeSame(200);

        $this->client->submitForm('Salvar', [
            'book[title]' => 'Testing',
            'book[price]' => '10,99',
        ]);

        self::assertResponseRedirects('/book');
        self::assertSame(1, $this->repository->count([]));

        $book = $this->repository->findAll()[0];

        self::assertSame('Testing', $book->getTitle());
        self::assertSame(1099, $book->getPriceInCents());
    }

    public function testNewWithCover(): void
    {
        $this->client->request('GET', sprintf('%snew', $this->path));

        $this->client->submitForm('Salvar', [
            'book[title]' => 'TestWithCover',
            'book[price]' => '10',
            'book[cover]' => __DIR__ . '/test.jpg'
        ]);

        /** @var Book */
        $book = $this->repository->findAll()[0];

        self::assertResponseRedirects('/book');

        self::assertNotNull($book->getCoverPath());

        self::assertFileExists(__DIR__ . '/uploadTest');
    }

    public function testShow(): void
    {
        $fixture = new Book();
        $fixture->setTitle('Title');
        $fixture->setPriceInCents(1000);

        $this->manager->persist($fixture);
        $this->manager->flush();

        $this->client->request('GET', sprintf('%s%s', $this->path, $fixture->getId()));

        self::assertResponseStatusCodeSame(200);
        self::assertPageTitleContains('Title');
    }

    public function testEdit(): void
    {
        $fixture = new Book();
        $fixture->setTitle('Value');
        $fixture->setPriceInCents(1000);

        $this->manager->persist($fixture);
        $this->manager->flush();

        $this->client->request('GET', sprintf('%s%s/edit', $this->path, $fixture->getId()));

        $this->client->submitForm('Editar', [
            'book[title]' => 'Something New',
            'book[price]' => '10,80',
        ]);

        self::assertResponseRedirects('/book');

        $fixture = $this->repository->findAll();

        self::assertSame('Something New', $fixture[0]->getTitle());
        self::assertSame(1080, $fixture[0]->getPriceInCents());
    }

    public function testEditWithCover(): void
    {
        $book = new Book();
        $book->setTitle('Value');
        $book->setPriceInCents(1000);

        $this->manager->persist($book);
        $this->manager->flush();

        $this->client->request('GET', sprintf('%s%s/edit', $this->path, $book->getId()));

        $this->client->submitForm('Editar', [
            'book[title]' => 'Something New',
            'book[cover]' => __DIR__ . '/test.jpg',
        ]);

        self::assertResponseRedirects('/book');

        $book = $this->repository->findAll()[0];

        self::assertSame('Something New', $book->getTitle());

        self::assertNotNull($book->getCoverPath());

        self::assertFileExists(__DIR__ . '/uploadTest');
    }

    public function testRemove(): void
    {
        $fixture = new Book();
        $fixture->setTitle('Value');
        $fixture->setPriceInCents(1000);

        $this->manager->persist($fixture);
        $this->manager->flush();

        $this->client->request('GET', sprintf('%s%s', $this->path, $fixture->getId()));
        $this->client->submitForm('Delete');

        self::assertResponseRedirects('/book');
        self::assertSame(0, $this->repository->count([]));
    }
    protected function tearDown(): void
    {
        $file = new Filesystem;
        $file->remove(__DIR__ . '/uploadTest');
        parent::tearDown();
    }
}
