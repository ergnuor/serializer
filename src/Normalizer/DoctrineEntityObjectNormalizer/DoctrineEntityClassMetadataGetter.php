<?php
declare(strict_types=1);

namespace Ergnuor\Serializer\Normalizer\DoctrineEntityObjectNormalizer;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;

class DoctrineEntityClassMetadataGetter implements DoctrineEntityClassMetadataGetterInterface
{
    /** @var EntityManagerInterface[] */
    private array $entityManagers;

    public function __construct(array $entityManagers)
    {
        $this->setEntityManagers($entityManagers);
    }

    private function setEntityManagers(array $entityManagers): void
    {
        if (empty($entityManagers)) {
            throw new \RuntimeException(
                sprintf(
                    'Empty entity manager list passed to "%s"',
                    get_class($this)
                )
            );
        }

        foreach ($entityManagers as $entityManager) {
            if (!($entityManager instanceof EntityManagerInterface)) {
                throw new \RuntimeException(
                    sprintf(
                        'Expected "%s" instance in "%s"',
                        EntityManagerInterface::class,
                        get_class($this)
                    )
                );
            }

            $this->entityManagers[] = $entityManager;
        }
    }

    public function getClassMetadata(string $className): ?ClassMetadata
    {
        foreach ($this->entityManagers as $entityManager) {
            if ($entityManager->getMetadataFactory()->hasMetadataFor($className)) {
                return $entityManager->getClassMetadata($className);
            }
        }

        return null;
    }
}