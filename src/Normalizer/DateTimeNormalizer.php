<?php
declare(strict_types=1);

namespace Ergnuor\Serializer\Normalizer;

use Symfony\Component\Serializer\Exception\InvalidArgumentException;

class DateTimeNormalizer extends \Symfony\Component\Serializer\Normalizer\DateTimeNormalizer
{
    public const NORMALIZE_AS_OBJECT = 'normalizer.datetime.context.as_object';

    public function normalize(mixed $object, string $format = null, array $context = []): string
    {
        if (!$object instanceof \DateTimeInterface) {
            throw new InvalidArgumentException('The object must implement the "\DateTimeInterface".');
        }

//        if (
//            isset($context[DateTimeNormalizer::NORMALIZE_AS_OBJECT]) &&
//            $context[DateTimeNormalizer::NORMALIZE_AS_OBJECT]
//        ) {
//            return $object;
//        }

        return parent::normalize($object, $format, $context);
    }

    public function denormalize(
        mixed $data,
        string $type,
        string $format = null,
        array $context = []
    ): \DateTimeInterface {
        if ($data instanceof \DateTimeInterface) {
            if (\DateTime::class === $type) {
                if ($data instanceof \DateTimeImmutable) {
                    return \DateTime::createFromImmutable($data);
                }

                return clone $data;
            }

            if ($data instanceof \DateTime) {
                return \DateTimeImmutable::createFromMutable($data);
            }

            return clone $data;
        }

        return parent::denormalize($data, $type, $format, $context);
    }
}
