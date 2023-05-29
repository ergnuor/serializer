<?php

declare(strict_types=1);

namespace Ergnuor\Serializer\Normalizer;

use Doctrine\Common\Collections\ArrayCollection;
use Symfony\Component\PropertyInfo\Type;
use Symfony\Component\Serializer\Exception\LogicException;
use Symfony\Component\Serializer\Normalizer\CacheableSupportsMethodInterface;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use Symfony\Component\Serializer\SerializerAwareInterface;
use Symfony\Component\Serializer\SerializerAwareTrait;

class DoctrineCollectionNormalizer implements SerializerAwareInterface, NormalizerInterface, DenormalizerInterface, CacheableSupportsMethodInterface
{
    use SerializerAwareTrait;

    public function supportsDenormalization($data, string $type, string $format = null)
    {
        return is_a($type, ArrayCollection::class, true);
    }

    public function supportsNormalization($data, string $format = null)
    {
        return $data instanceof ArrayCollection;
    }

    public function denormalize($data, string $type, string $format = null, array $context = [])
    {
        if (!$this->serializer instanceof DenormalizerInterface) {
            throw new LogicException(sprintf('Cannot denormalize collection "%s" because injected serializer is not a denormalizer.', $type));
        }

        if (
            isset($context['value_type']) &&
            $context['value_type'] instanceof Type &&
            $context['value_type']->getBuiltinType() === Type::BUILTIN_TYPE_OBJECT
        ) {
            $data = $this->serializer->denormalize($data, $context['value_type']->getClassName() . '[]', $format, $context);
        }

        return new ArrayCollection($data);
    }

    /**
     * @param ArrayCollection $object
     * @param string|null $format
     * @param array $context
     * @return array|null
     */
    public function normalize($object, string $format = null, array $context = [])
    {
        return $object->map(function ($item) use ($object, $format, $context) {
            if (!$this->serializer instanceof NormalizerInterface) {
                throw new LogicException(sprintf('Cannot normalize collection "%s" item "%s" because the injected serializer is not a normalizer.',
                    get_debug_type($object), get_debug_type($item)));
            }

            return $this->serializer->normalize($item, $format, $context);
        })->toArray();
    }

    public function hasCacheableSupportsMethod(): bool
    {
        return true;
    }
}
