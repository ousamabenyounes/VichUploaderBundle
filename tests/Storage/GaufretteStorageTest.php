<?php

namespace Vich\UploaderBundle\Tests\Storage;

use Gaufrette\Adapter;
use Gaufrette\Exception\FileNotFound;
use Gaufrette\Filesystem;
use Knp\Bundle\GaufretteBundle\FilesystemMap;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use Vich\UploaderBundle\Storage\GaufretteStorage;
use Vich\UploaderBundle\Storage\StorageInterface;

/**
 * @author Leszek Prabucki <leszek.prabucki@gmail.com>
 */
class GaufretteStorageTest extends StorageTestCase
{
    protected FilesystemMap|MockObject $filesystemMap;

    protected function getStorage(): StorageInterface
    {
        return new GaufretteStorage($this->factory, $this->filesystemMap);
    }

    protected function setUp(): void
    {
        if (!\class_exists(FilesystemMap::class)) {
            self::markTestSkipped('GaufretteBundle is not installed.');
        }
        $this->filesystemMap = $this->createMock(FilesystemMap::class);

        parent::setUp();
    }

    /**
     * Test the remove method skips trying to remove a file whose file name
     * property value returns null.
     */
    public function testRemoveSkipsNullFileNameProperty(): void
    {
        $this->mapping
            ->expects($this->once())
            ->method('getFileName')
            ->willReturn(null);

        $this->mapping
            ->expects($this->never())
            ->method('getUploadDir');

        $this->storage->remove($this->object, $this->mapping);
    }

    #[DataProvider('pathProvider')]
    public function testResolvePath(string $protocol, string $filesystemKey, ?string $uploadDir, string $expectedPath, bool $relative): void
    {
        $this->mapping
            ->method('getUploadDestination')
            ->willReturn($filesystemKey);

        $this->mapping
            ->expects($this->once())
            ->method('getUploadDir')
            ->willReturn($uploadDir);

        $this->mapping
            ->expects($this->once())
            ->method('getFileName')
            ->willReturn('file.txt');

        $this->factory
            ->expects($this->once())
            ->method('fromField')
            ->with($this->object, 'file_field')
            ->willReturn($this->mapping);

        $this->storage = new GaufretteStorage($this->factory, $this->filesystemMap, $protocol);
        $path = $this->storage->resolvePath($this->object, 'file_field', null, $relative);

        self::assertEquals($expectedPath, $path);
    }

    public function testResolveUri(): void
    {
        $this->mapping
            ->expects($this->once())
            ->method('getUriPrefix')
            ->willReturn('/uploads');

        $this->mapping
            ->expects($this->once())
            ->method('getFileName')
            ->willReturn('file.txt');

        $this->factory
            ->expects($this->once())
            ->method('fromField')
            ->with($this->object, 'file_field')
            ->willReturn($this->mapping);

        $this->storage = new GaufretteStorage($this->factory, $this->filesystemMap, 'gaufrette');
        $path = $this->storage->resolveUri($this->object, 'file_field');

        self::assertEquals('/uploads/file.txt', $path);
    }

    public function testResolveUriFileNull(): void
    {
        $this->mapping
            ->expects($this->once())
            ->method('getFileName')
            ->willReturn('');

        $this->factory
            ->expects($this->once())
            ->method('fromField')
            ->with($this->object, 'file_field')
            ->willReturn($this->mapping);

        $this->storage = new GaufretteStorage($this->factory, $this->filesystemMap, 'gaufrette');
        $path = $this->storage->resolveUri($this->object, 'file_field');

        self::assertNull($path);
    }

    public function testResolveUriWithZeroDirectory(): void
    {
        $this->mapping
            ->expects($this->once())
            ->method('getUriPrefix')
            ->willReturn('/uploads');

        $this->mapping
            ->expects($this->once())
            ->method('getUploadDir')
            ->willReturn('0');

        $this->mapping
            ->expects($this->once())
            ->method('getFileName')
            ->willReturn('file.txt');

        $this->factory
            ->expects($this->once())
            ->method('fromField')
            ->with($this->object, 'file_field')
            ->willReturn($this->mapping);

        $this->storage = new GaufretteStorage($this->factory, $this->filesystemMap, 'gaufrette');
        $path = $this->storage->resolveUri($this->object, 'file_field');

        self::assertEquals('/uploads/0/file.txt', $path);
    }

    public static function pathProvider(): array
    {
        return [
            // protocol, fs identifier, upload dir, full path, relative
            ['gaufrette', 'filesystemKey', null,   'gaufrette://filesystemKey/file.txt', false],
            ['data',      'filesystemKey', null,   'data://filesystemKey/file.txt', false],
            ['gaufrette', 'filesystemKey', 'foo',  'gaufrette://filesystemKey/foo/file.txt', false],
            ['gaufrette', 'filesystemKey', '0',    'gaufrette://filesystemKey/0/file.txt', false],
            ['gaufrette', 'filesystemKey', null,   'file.txt', true],
            ['gaufrette', 'filesystemKey', 'foo',  'foo/file.txt', true],
            ['gaufrette', 'filesystemKey', '0',    '0/file.txt', true],
        ];
    }

    /**
     * Test the remove method does delete file from gaufrette filesystem.
     */
    public function testThatRemoveMethodDoesDeleteFile(): void
    {
        $this->mapping
            ->method('getUploadDestination')
            ->willReturn('filesystemKey');
        $this->mapping
            ->expects($this->once())
            ->method('getFileName')
            ->willReturn('file.txt');

        $filesystem = $this->getFilesystemMock();
        $filesystem
            ->expects($this->once())
            ->method('delete')
            ->with('file.txt')
            ->willReturn(true);

        $this
            ->filesystemMap
            ->expects($this->once())
            ->method('get')
            ->with('filesystemKey')
            ->willReturn($filesystem);

        $this->storage->remove($this->object, $this->mapping);
    }

    /**
     * Test that FileNotFound exception is caught.
     */
    public function testRemoveNotFoundFile(): void
    {
        // the exception is caught in the UploadHandler.
        $this->expectException(FileNotFound::class);
        $this->mapping
            ->method('getUploadDestination')
            ->willReturn('filesystemKey');
        $this->mapping
            ->expects($this->once())
            ->method('getFileName')
            ->willReturn('file.txt');

        $filesystem = $this->getFilesystemMock();
        $filesystem
            ->expects($this->once())
            ->method('delete')
            ->with('file.txt')
            ->will($this->throwException(new FileNotFound('File Not Found')));

        $this
            ->filesystemMap
            ->expects($this->once())
            ->method('get')
            ->with('filesystemKey')
            ->willReturn($filesystem);

        $this->storage->remove($this->object, $this->mapping);
    }

    public function testUploadSetsMetadataWhenUsingMetadataSupporterAdapter(): void
    {
        $filesystem = $this->getFilesystemMock();
        $file = $this->getUploadedFileMock();
        $adapter = $this->createMock(Adapter::class);

        $file
            ->expects($this->once())
            ->method('getClientOriginalName')
            ->willReturn('filename');

        $file
            ->expects($this->once())
            ->method('getPathname')
            ->willReturn($this->getValidUploadDir().\DIRECTORY_SEPARATOR.'test.txt');

        $this->mapping
            ->expects($this->once())
            ->method('getFile')
            ->willReturn($file);

        $this->mapping
            ->expects($this->once())
            ->method('getUploadName')
            ->with($this->object)
            ->willReturn('filename');

        $this->mapping
            ->expects($this->once())
            ->method('getUploadDestination')
            ->willReturn('filesystemKey');

        $this->filesystemMap
            ->expects($this->once())
            ->method('get')
            ->with('filesystemKey')
            ->willReturn($filesystem);

        $filesystem
            ->method('getAdapter')
            ->willReturn($adapter);

        $filesystem
            ->expects($this->once())
            ->method('write')
            ->with('filename', 'some content');

        $this->storage->upload($this->object, $this->mapping);
    }

    public function testUploadDoesNotSetMetadataWhenUsingNonMetadataSupporterAdapter(): void
    {
        $adapter = $this->createMock(Adapter::class);
        $filesystem = $this->getFilesystemMock();
        $file = $this->getUploadedFileMock();

        $file
            ->expects($this->once())
            ->method('getClientOriginalName')
            ->willReturn('filename');

        $file
            ->expects($this->once())
            ->method('getPathname')
            ->willReturn($this->getValidUploadDir().\DIRECTORY_SEPARATOR.'test.txt');

        $this->mapping
            ->expects($this->once())
            ->method('getFile')
            ->willReturn($file);

        $this->mapping
            ->expects($this->once())
            ->method('getUploadName')
            ->with($this->object)
            ->willReturn('filename');

        $this->mapping
            ->expects($this->once())
            ->method('getUploadDir')
            ->willReturn('');

        $this->mapping
            ->expects($this->once())
            ->method('getUploadDestination')
            ->willReturn('filesystemKey');

        $this->filesystemMap
            ->expects($this->once())
            ->method('get')
            ->with('filesystemKey')
            ->willReturn($filesystem);

        $filesystem
            ->method('getAdapter')
            ->willReturn($adapter);

        $filesystem
            ->expects($this->once())
            ->method('write')
            ->with('filename', 'some content');

        $this->storage->upload($this->object, $this->mapping);
    }

    protected function getFilesystemMock(): Filesystem|MockObject
    {
        return $this->createMock(Filesystem::class);
    }
}
