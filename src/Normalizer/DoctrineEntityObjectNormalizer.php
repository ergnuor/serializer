<?php

declare(strict_types=1);

namespace Ergnuor\Serializer\Normalizer;

use Doctrine\Common\Proxy\Proxy;
use Doctrine\ORM\Mapping\ClassMetadata;
use Ergnuor\Serializer\Normalizer\DoctrineEntityObjectNormalizer\DoctrineEntityClassMetadataGetterInterface;
use Symfony\Component\PropertyAccess\PropertyAccessorInterface;
use Symfony\Component\PropertyInfo\PropertyTypeExtractorInterface;
use Symfony\Component\Serializer\Mapping\ClassDiscriminatorResolverInterface;
use Symfony\Component\Serializer\Mapping\Factory\ClassMetadataFactoryInterface;
use Symfony\Component\Serializer\NameConverter\NameConverterInterface;
use Symfony\Component\Serializer\Normalizer\AbstractNormalizer;

class DoctrineEntityObjectNormalizer extends ObjectNormalizer
{
    public const SKIP_NOT_INITIALIZED_PROXIES = 'skipNotInitializedProxies';
    private DoctrineEntityClassMetadataGetterInterface $classMetadataGetter;

    public function __construct(
        DoctrineEntityClassMetadataGetterInterface $classMetadataGetter,
        ClassMetadataFactoryInterface $classMetadataFactory = null,
        NameConverterInterface $nameConverter = null,
        PropertyAccessorInterface $propertyAccessor = null,
        PropertyTypeExtractorInterface $propertyTypeExtractor = null,
        ClassDiscriminatorResolverInterface $classDiscriminatorResolver = null,
        callable $objectClassResolver = null,
        array $defaultContext = []
    ) {
        parent::__construct($classMetadataFactory, $nameConverter, $propertyAccessor, $propertyTypeExtractor,
            $classDiscriminatorResolver, $objectClassResolver, $defaultContext);

        $this->defaultContext = array_merge(
            $this->defaultContext,
            [
                self::SKIP_NOT_INITIALIZED_PROXIES => true,
            ]
        );

        $this->classMetadataGetter = $classMetadataGetter;
    }

    public function normalize($object, string $format = null, array $context = [])
    {
        return parent::normalize($object, $format, $this->normalizeContext($context));
    }

    /**
     * We normalize the context for ease of use and for uniformity,
     * because the context is used when generating the caching key in the @see ObjectNormalizer::getCacheKey method
     */
    private function normalizeContext(array $context): array
    {
        $context = array_replace(
            $this->defaultContext,
            $context
        );

        $context[self::SKIP_NOT_INITIALIZED_PROXIES] = (bool)$context[self::SKIP_NOT_INITIALIZED_PROXIES];

        return $context;
    }

    public function denormalize(mixed $data, string $type, string $format = null, array $context = [])
    {
        return parent::denormalize($data, $type, $format, $this->normalizeContext($context));
    }

    public function supportsDenormalization($data, string $type, string $format = null)
    {
        return false;
    }

    protected function getAttributes(object $object, ?string $format, array $context): array
    {
        $attributes = parent::getAttributes($object, $format, $context);

        $attributes = array_flip($attributes);

        $className = get_class($object);

        $classMetadata = $this->getClassMetadata($className);

        $associationNames = array_flip($classMetadata->getAssociationNames());

        $reflectionClass = new \ReflectionClass($className);

        /** @var \ReflectionProperty[] $associationProperties */
        $associationProperties = [];
        foreach ($reflectionClass->getProperties() as $property) {
            if (
                isset($associationNames[$property->getName()]) &&
                isset($attributes[$property->getName()])
            ) {
                $associationProperties[] = $property;
            }
        }

        foreach ($associationProperties as $property) {
            $property->setAccessible(true);
            $value = $property->getValue($object);

            if ($value instanceof Proxy) {
                if (
                    $context[self::SKIP_NOT_INITIALIZED_PROXIES] &&
                    !$value->__isInitialized()
                ) {
                    unset($attributes[$property->getName()]);
                }
            } elseif (is_object($value)) {
                $objectHash = spl_object_hash($value);
                if (
                    isset($context[AbstractNormalizer::CIRCULAR_REFERENCE_LIMIT_COUNTERS]) &&
                    isset($context[AbstractNormalizer::CIRCULAR_REFERENCE_LIMIT_COUNTERS][$objectHash])
                ) {
                    unset($attributes[$property->getName()]);
                }
            }
        }

        $attributes = array_flip($attributes);

        return $attributes;
    }

    public function supportsNormalization($data, string $format = null)
    {
        if (!is_object($data)) {
            return false;
        }

        $classMetadata = $this->getClassMetadata($data);

        if ($classMetadata === null) {
            return false;
        }

        return true;
    }

    private function getClassMetadata($objectOrClass): ?ClassMetadata
    {
        if (is_object($objectOrClass)) {
            $className = get_class($objectOrClass);
        } elseif (is_string($objectOrClass)) {
            $className = $objectOrClass;
        } else {
            $type = get_debug_type($objectOrClass);
            throw new \RuntimeException("Object or class name expected. '{$type}' given.'");
        }

        return $this->classMetadataGetter->getClassMetadata($className);
    }
}
