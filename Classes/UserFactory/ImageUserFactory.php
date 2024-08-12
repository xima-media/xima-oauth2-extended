<?php

namespace Xima\XimaOauth2Extended\UserFactory;

use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\MathUtility;
use TYPO3\CMS\Core\Utility\VersionNumberUtility;
use Xima\XimaOauth2Extended\ResourceResolver\ProfileImageResolverInterface;

final class ImageUserFactory
{
    public function __construct(
        private ProfileImageResolverInterface $resolver,
        private readonly string $fileStorageIdentifier
    ) {
    }

    public function addProfileImageForBackendUser(int $beUserUid): bool
    {
        $fileStorageUid = self::getFileStorageUidFromIdentifier($this->fileStorageIdentifier);
        if ($fileStorageUid === null) {
            return false;
        }

        $imageContent = $this->resolver->resolveProfileImage();
        if (!$imageContent) {
            return false;
        }

        try {
            $fileIdentifier = $this->writeFile($imageContent, $this->fileStorageIdentifier);
            $sysFileUid = $this->createSysFile($fileIdentifier, $fileStorageUid);
            self::createSysFileReferenceForUser($sysFileUid, 'be_users', 'avatar', $beUserUid);
        } catch (\Exception) {
            return false;
        }

        return true;
    }

    private static function getFileStorageUidFromIdentifier(string $identifier): ?int
    {
        $identifierParts = GeneralUtility::trimExplode(':', $identifier, true);
        if (count($identifierParts) === 2 && MathUtility::canBeInterpretedAsInteger($identifierParts[0])) {
            return (int)$identifierParts[0];
        }
        return null;
    }

    private function writeFile(string $imageContent, string $fileStorageIdentifier): string
    {
        // create directory
        $fileStoragePath = self::getAbsoluteImageStoragePathFromIdentifier($fileStorageIdentifier);
        if (!file_exists($fileStoragePath)) {
            GeneralUtility::mkdir_deep($fileStoragePath);
        }

        // write file
        $fileName = sha1($imageContent) . '.jpg';
        $filePath = $fileStoragePath . '/' . $fileName;
        GeneralUtility::writeFile($filePath, $imageContent);

        // public path
        $relativeStoragePath = self::getRelativeFileStoragePathFromIdentifier($fileStorageIdentifier);
        return $relativeStoragePath . '/' . $fileName;
    }

    private static function getAbsoluteImageStoragePathFromIdentifier(string $identifier): ?string
    {
        $storageUid = self::getFileStorageUidFromIdentifier($identifier) ?? 0;
        $absoluteStoragePath = self::getAbsoluteFileStoragePathFromUid($storageUid);
        $relativeImagePath = self::getRelativeFileStoragePathFromIdentifier($identifier);

        if (!$absoluteStoragePath || !$relativeImagePath) {
            return null;
        }

        return $absoluteStoragePath . $relativeImagePath;
    }

    private static function getAbsoluteFileStoragePathFromUid(int $uid): ?string
    {
        $qb = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('sys_file_storage');
        $result = $qb->select('configuration')
            ->from('sys_file_storage')
            ->where($qb->expr()->eq('uid', $qb->createNamedParameter($uid, Connection::PARAM_INT)))
            ->executeQuery()
            ->fetchAllAssociative();

        $config = GeneralUtility::xml2array($result[0]['configuration']);
        $basePath = $config['data']['sDEF']['lDEF']['basePath']['vDEF'] ?? '';
        $pathType = $config['data']['sDEF']['lDEF']['pathType']['vDEF'] ?? '';

        if ($pathType === 'relative') {
            return Environment::getPublicPath() . '/' . $basePath;
        }

        if ($pathType === 'absolute') {
            return $basePath;
        }

        return null;
    }

    private static function getRelativeFileStoragePathFromIdentifier(string $identifier): ?string
    {
        $identifierParts = GeneralUtility::trimExplode(':', $identifier, true);
        if (count($identifierParts) === 2 && MathUtility::canBeInterpretedAsInteger($identifierParts[0])) {
            return $identifierParts[1];
        }
        return null;
    }

    private function createSysFile(string $fileIdentifier, int $storage): int
    {
        $now = (new \DateTime())->getTimestamp();

        $insertValues = [
            'tstamp' => $now,
            'type' => 2,
            'name' => self::getFileNameFromIdentifier($fileIdentifier),
            'identifier' => $fileIdentifier,
            'creation_date' => $now,
            'modification_date' => $now,
            'storage' => $storage,
        ];

        $qb = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('sys_file');
        $qb->insert('sys_file')
            ->values($insertValues)
            ->executeQuery();

        $qb = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('sys_file');
        $result = $qb->select('uid')
            ->from('sys_file')
            ->where($qb->expr()->eq('identifier', $qb->createNamedParameter($fileIdentifier)))
            ->executeQuery()
            ->fetchFirstColumn();

        return $result[0];
    }

    private static function getFileNameFromIdentifier(string $identifier): string
    {
        $identifierParts = GeneralUtility::trimExplode('/', $identifier);
        $fileName = array_pop($identifierParts) ?? '';
        $withoutExtensionParts = GeneralUtility::trimExplode('.', $fileName);
        return $withoutExtensionParts[0];
    }

    private static function createSysFileReferenceForUser(
        int $sysFileUid,
        string $tableName,
        string $fieldName,
        int $uid
    ): void {
        $now = (new \DateTime())->getTimestamp();

        $insertValues = [
            'tstamp' => $now,
            'crdate' => $now,
            'uid_local' => $sysFileUid,
            'uid_foreign' => $uid,
            'tablenames' => $tableName,
            'fieldname' => $fieldName,
        ];

        $version = VersionNumberUtility::convertVersionStringToArray(VersionNumberUtility::getNumericTypo3Version());
        if ($version['version_main'] < 12) {
            $insertValues['table_local'] = 'sys_file';
        }

        $qb = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('sys_file_reference');
        $qb->insert('sys_file_reference')
            ->values($insertValues)
            ->executeQuery();
    }
}
