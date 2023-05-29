<?php

declare(strict_types=1);

namespace Ergnuor\Serializer\Normalizer;

use Symfony\Component\PropertyAccess\Exception\InvalidArgumentException;
use Symfony\Component\PropertyAccess\Exception\NoSuchPropertyException;
use Symfony\Component\PropertyAccess\PropertyAccess;
use Symfony\Component\PropertyAccess\PropertyAccessorInterface;
use Symfony\Component\PropertyInfo\PropertyTypeExtractorInterface;
use Symfony\Component\PropertyInfo\Type;
use Symfony\Component\Serializer\Encoder\CsvEncoder;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Encoder\XmlEncoder;
use Symfony\Component\Serializer\Exception\ExtraAttributesException;
use Symfony\Component\Serializer\Exception\LogicException;
use Symfony\Component\Serializer\Exception\MissingConstructorArgumentsException;
use Symfony\Component\Serializer\Exception\NotNormalizableValueException;
use Symfony\Component\Serializer\Mapping\AttributeMetadataInterface;
use Symfony\Component\Serializer\Mapping\ClassDiscriminatorResolverInterface;
use Symfony\Component\Serializer\Mapping\Factory\ClassMetadataFactoryInterface;
use Symfony\Component\Serializer\NameConverter\NameConverterInterface;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;

/**
 * The main idea is to expand the functionality of \Symfony\Component\Serializer\Normalizer\ObjectNormalizer:
 *
 *  - denormalize collection objects as objects, not as arrays
 *    @see \Ergnuor\Serializer\Normalizer\ObjectNormalizer::validateAndDenormalize
 *
 * There was a hope to minimize the number of changes by using inheritance,
 * but due to the fact that some of the parent methods are private, we had to copy them
 */
class ObjectNormalizer extends \Symfony\Component\Serializer\Normalizer\ObjectNormalizer
{
    /**
     * @var callable|\Closure
     */
    protected $objectClassResolver;
    private ?PropertyTypeExtractorInterface $propertyTypeExtractor;
    private $typesCache = [];

    public function __construct(
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

        $this->propertyTypeExtractor = $propertyTypeExtractor;

        $this->objectClassResolver = $objectClassResolver ?? function ($class) {
            return \is_object($class) ? \get_class($class) : $class;
        };
    }

    /**
     * The logic we want to modify
     * is in the @see \Ergnuor\Serializer\Normalizer\ObjectNormalizer::validateAndDenormalize method, which is private.
     * So we had to copy this method as well.
     */
    public function denormalize(mixed $data, string $type, string $format = null, array $context = [])
    {
        if (!isset($context['cache_key'])) {
            $context['cache_key'] = $this->getCacheKey($format, $context);
        }

        $this->validateCallbackContext($context);

        if (null === $data && isset($context['value_type']) && $context['value_type'] instanceof Type && $context['value_type']->isNullable()) {
            return null;
        }

        $allowedAttributes = $this->getAllowedAttributes($type, $context, true);
        $normalizedData = $this->prepareForDenormalization($data);
        $extraAttributes = [];

        $mappedClass = $this->getMappedClass($normalizedData, $type, $context);

        $nestedAttributes = $this->getNestedAttributes($mappedClass);
        $nestedData = [];
        $propertyAccessor = PropertyAccess::createPropertyAccessor();
        foreach ($nestedAttributes as $property => $serializedPath) {
            if (null === $value = $propertyAccessor->getValue($normalizedData, $serializedPath)) {
                continue;
            }
            $nestedData[$property] = $value;
            $normalizedData = $this->removeNestedValue($serializedPath->getElements(), $normalizedData);
        }

        $normalizedData = array_merge($normalizedData, $nestedData);

        $object = $this->instantiateObject($normalizedData, $mappedClass, $context, new \ReflectionClass($mappedClass), $allowedAttributes, $format);
        $resolvedClass = ($this->objectClassResolver)($object);

        foreach ($normalizedData as $attribute => $value) {
            if ($this->nameConverter) {
                $notConverted = $attribute;
                $attribute = $this->nameConverter->denormalize($attribute, $resolvedClass, $format, $context);
                if (isset($nestedData[$notConverted]) && !isset($nestedData[$attribute])) {
                    throw new LogicException(sprintf('Duplicate values for key "%s" found. One value is set via the SerializedPath annotation: "%s", the other one is set via the SerializedName annotation: "%s".', $notConverted, implode('->', $nestedAttributes[$notConverted]->getElements()), $attribute));
                }
            }

            $attributeContext = $this->getAttributeDenormalizationContext($resolvedClass, $attribute, $context);

            if ((false !== $allowedAttributes && !\in_array($attribute, $allowedAttributes)) || !$this->isAllowedAttribute($resolvedClass, $attribute, $format, $context)) {
                if (!($context[self::ALLOW_EXTRA_ATTRIBUTES] ?? $this->defaultContext[self::ALLOW_EXTRA_ATTRIBUTES])) {
                    $extraAttributes[] = $attribute;
                }

                continue;
            }

            if ($attributeContext[self::DEEP_OBJECT_TO_POPULATE] ?? $this->defaultContext[self::DEEP_OBJECT_TO_POPULATE] ?? false) {
                try {
                    $attributeContext[self::OBJECT_TO_POPULATE] = $this->getAttributeValue($object, $attribute, $format, $attributeContext);
                } catch (NoSuchPropertyException) {
                }
            }

            $types = $this->getTypes($resolvedClass, $attribute);

            if (null !== $types) {
                try {
                    $value = $this->validateAndDenormalize($types, $resolvedClass, $attribute, $value, $format, $attributeContext);
                } catch (NotNormalizableValueException $exception) {
                    if (isset($context['not_normalizable_value_exceptions'])) {
                        $context['not_normalizable_value_exceptions'][] = $exception;
                        continue;
                    }
                    throw $exception;
                }
            }

            $value = $this->applyCallbacks($value, $resolvedClass, $attribute, $format, $attributeContext);

            try {
                $this->setAttributeValue($object, $attribute, $value, $format, $attributeContext);
            } catch (InvalidArgumentException $e) {
                $exception = NotNormalizableValueException::createForUnexpectedDataType(
                    sprintf('Failed to denormalize attribute "%s" value for class "%s": '.$e->getMessage(), $attribute, $type),
                    $data,
                    ['unknown'],
                    $context['deserialization_path'] ?? null,
                    false,
                    $e->getCode(),
                    $e
                );
                if (isset($context['not_normalizable_value_exceptions'])) {
                    $context['not_normalizable_value_exceptions'][] = $exception;
                    continue;
                }
                throw $exception;
            }
        }

        if ($extraAttributes) {
            throw new ExtraAttributesException($extraAttributes);
        }

        return $object;
    }

    /**
     * Builds the cache key for the attributes cache.
     *
     * The key must be different for every option in the context that could change which attributes should be handled.
     */
    private function getCacheKey(?string $format, array $context): bool|string
    {
        foreach ($context[self::EXCLUDE_FROM_CACHE_KEY] ?? $this->defaultContext[self::EXCLUDE_FROM_CACHE_KEY] as $key) {
            unset($context[$key]);
        }
        unset($context[self::EXCLUDE_FROM_CACHE_KEY]);
        unset($context[self::OBJECT_TO_POPULATE]);
        unset($context['cache_key']); // avoid artificially different keys

        try {
            return hash('xxh128', $format.serialize([
                    'context' => $context,
                    'ignored' => $context[self::IGNORED_ATTRIBUTES] ?? $this->defaultContext[self::IGNORED_ATTRIBUTES],
                ]));
        } catch (\Exception) {
            // The context cannot be serialized, skip the cache
            return false;
        }
    }

    /**
     * @return class-string
     *
     * The method was copied because it is private, but it is accessed in other methods
     */
    private function getMappedClass(array $data, string $class, array $context): string
    {
        if (!$mapping = $this->classDiscriminatorResolver?->getMappingForClass($class)) {
            return $class;
        }

        if (null === $type = $data[$mapping->getTypeProperty()] ?? null) {
            throw NotNormalizableValueException::createForUnexpectedDataType(sprintf('Type property "%s" not found for the abstract object "%s".', $mapping->getTypeProperty(), $class), null, ['string'], isset($context['deserialization_path']) ? $context['deserialization_path'].'.'.$mapping->getTypeProperty() : $mapping->getTypeProperty(), false);
        }

        if (null === $mappedClass = $mapping->getClassForType($type)) {
            throw NotNormalizableValueException::createForUnexpectedDataType(sprintf('The type "%s" is not a valid value.', $type), $type, ['string'], isset($context['deserialization_path']) ? $context['deserialization_path'].'.'.$mapping->getTypeProperty() : $mapping->getTypeProperty(), true);
        }

        return $mappedClass;
    }

    /**
     * Returns all attributes with a SerializedPath annotation and the respective path.
     *
     * The method was copied because it is private, but it is accessed in other methods
     */
    private function getNestedAttributes(string $class): array
    {
        if (!$this->classMetadataFactory?->hasMetadataFor($class)) {
            return [];
        }

        $properties = [];
        $serializedPaths = [];
        $classMetadata = $this->classMetadataFactory->getMetadataFor($class);
        foreach ($classMetadata->getAttributesMetadata() as $name => $metadata) {
            if (!$serializedPath = $metadata->getSerializedPath()) {
                continue;
            }
            $pathIdentifier = implode(',', $serializedPath->getElements());
            if (isset($serializedPaths[$pathIdentifier])) {
                throw new LogicException(sprintf('Duplicate serialized path: "%s" used for properties "%s" and "%s".', $pathIdentifier, $serializedPaths[$pathIdentifier], $name));
            }
            $serializedPaths[$pathIdentifier] = $name;
            $properties[$name] = $serializedPath;
        }

        return $properties;
    }

    /**
     * The method was copied because it is private, but it is accessed in other methods
     */
    private function removeNestedValue(array $path, array $data): array
    {
        $element = array_shift($path);
        if (!$path || !$data[$element] = $this->removeNestedValue($path, $data[$element])) {
            unset($data[$element]);
        }

        return $data;
    }

    /**
     * @return Type[]|null
     *
     * The method was copied because it is private, but it is accessed in other methods
     */
    private function getTypes(string $currentClass, string $attribute): ?array
    {
        if (null === $this->propertyTypeExtractor) {
            return null;
        }

        $key = $currentClass.'::'.$attribute;
        if (isset($this->typesCache[$key])) {
            return false === $this->typesCache[$key] ? null : $this->typesCache[$key];
        }

        if (null !== $types = $this->propertyTypeExtractor->getTypes($currentClass, $attribute)) {
            return $this->typesCache[$key] = $types;
        }

        if (null !== $this->classDiscriminatorResolver && null !== $discriminatorMapping = $this->classDiscriminatorResolver->getMappingForClass($currentClass)) {
            if ($discriminatorMapping->getTypeProperty() === $attribute) {
                return $this->typesCache[$key] = [
                    new Type(Type::BUILTIN_TYPE_STRING),
                ];
            }

            foreach ($discriminatorMapping->getTypesMapping() as $mappedClass) {
                if (null !== $types = $this->propertyTypeExtractor->getTypes($mappedClass, $attribute)) {
                    return $this->typesCache[$key] = $types;
                }
            }
        }

        $this->typesCache[$key] = false;

        return null;
    }

    /**
     * Validates the submitted data and denormalizes it.
     *
     * @param Type[] $types
     *
     * @throws NotNormalizableValueException
     * @throws ExtraAttributesException
     * @throws MissingConstructorArgumentsException
     * @throws LogicException|\Symfony\Component\Serializer\Exception\ExceptionInterface
     */
    private function validateAndDenormalize(array $types, string $currentClass, string $attribute, mixed $data, ?string $format, array $context): mixed
    {
        $expectedTypes = [];
        $isUnionType = \count($types) > 1;
        $extraAttributesException = null;
        $missingConstructorArgumentsException = null;
        foreach ($types as $type) {
            if (null === $data && $type->isNullable()) {
                return null;
            }

            $collectionValueType = $type->isCollection() ? $type->getCollectionValueTypes()[0] ?? null : null;

            // Fix a collection that contains the only one element
            // This is special to xml format only
            if ('xml' === $format && null !== $collectionValueType && (!\is_array($data) || !\is_int(key($data)))) {
                $data = [$data];
            }

            // This try-catch should cover all NotNormalizableValueException (and all return branches after the first
            // exception) so we could try denormalizing all types of an union type. If the target type is not an union
            // type, we will just re-throw the catched exception.
            // In the case of no denormalization succeeds with an union type, it will fall back to the default exception
            // with the acceptable types list.
            try {
                // In XML and CSV all basic datatypes are represented as strings, it is e.g. not possible to determine,
                // if a value is meant to be a string, float, int or a boolean value from the serialized representation.
                // That's why we have to transform the values, if one of these non-string basic datatypes is expected.
                if (\is_string($data) && (XmlEncoder::FORMAT === $format || CsvEncoder::FORMAT === $format)) {
                    if ('' === $data) {
                        if (Type::BUILTIN_TYPE_ARRAY === $builtinType = $type->getBuiltinType()) {
                            return [];
                        }

                        if ($type->isNullable() && \in_array($builtinType, [Type::BUILTIN_TYPE_BOOL, Type::BUILTIN_TYPE_INT, Type::BUILTIN_TYPE_FLOAT], true)) {
                            return null;
                        }
                    }

                    switch ($builtinType ?? $type->getBuiltinType()) {
                        case Type::BUILTIN_TYPE_BOOL:
                            // according to https://www.w3.org/TR/xmlschema-2/#boolean, valid representations are "false", "true", "0" and "1"
                            if ('false' === $data || '0' === $data) {
                                $data = false;
                            } elseif ('true' === $data || '1' === $data) {
                                $data = true;
                            } else {
                                throw NotNormalizableValueException::createForUnexpectedDataType(sprintf('The type of the "%s" attribute for class "%s" must be bool ("%s" given).', $attribute, $currentClass, $data), $data, [Type::BUILTIN_TYPE_BOOL], $context['deserialization_path'] ?? null);
                            }
                            break;
                        case Type::BUILTIN_TYPE_INT:
                            if (ctype_digit('-' === $data[0] ? substr($data, 1) : $data)) {
                                $data = (int) $data;
                            } else {
                                throw NotNormalizableValueException::createForUnexpectedDataType(sprintf('The type of the "%s" attribute for class "%s" must be int ("%s" given).', $attribute, $currentClass, $data), $data, [Type::BUILTIN_TYPE_INT], $context['deserialization_path'] ?? null);
                            }
                            break;
                        case Type::BUILTIN_TYPE_FLOAT:
                            if (is_numeric($data)) {
                                return (float) $data;
                            }

                            return match ($data) {
                                'NaN' => \NAN,
                                'INF' => \INF,
                                '-INF' => -\INF,
                                default => throw NotNormalizableValueException::createForUnexpectedDataType(sprintf('The type of the "%s" attribute for class "%s" must be float ("%s" given).', $attribute, $currentClass, $data), $data, [Type::BUILTIN_TYPE_FLOAT], $context['deserialization_path'] ?? null),
                            };
                    }
                }

                // We want to denormalize collection objects as objects, not as arrays
                if (
                    null !== $collectionValueType &&
                    $type->isCollection() &&
                    $type->getBuiltinType() === Type::BUILTIN_TYPE_OBJECT
                ) {
                    $builtinType = Type::BUILTIN_TYPE_OBJECT;
                    $class = $type->getClassName();

                    if (\count($collectionKeyType = $type->getCollectionKeyTypes()) > 0) {
                        [$context['key_type']] = $collectionKeyType;
                    }

                    $context['value_type'] = $collectionValueType;

                } elseif (null !== $collectionValueType && Type::BUILTIN_TYPE_OBJECT === $collectionValueType->getBuiltinType()) {
                    $builtinType = Type::BUILTIN_TYPE_OBJECT;
                    $class = $collectionValueType->getClassName().'[]';

                    if (\count($collectionKeyType = $type->getCollectionKeyTypes()) > 0) {
                        [$context['key_type']] = $collectionKeyType;
                    }

                    $context['value_type'] = $collectionValueType;
                } elseif ($type->isCollection() && \count($collectionValueType = $type->getCollectionValueTypes()) > 0 && Type::BUILTIN_TYPE_ARRAY === $collectionValueType[0]->getBuiltinType()) {
                    // get inner type for any nested array
                    [$innerType] = $collectionValueType;

                    // note that it will break for any other builtinType
                    $dimensions = '[]';
                    while (\count($innerType->getCollectionValueTypes()) > 0 && Type::BUILTIN_TYPE_ARRAY === $innerType->getBuiltinType()) {
                        $dimensions .= '[]';
                        [$innerType] = $innerType->getCollectionValueTypes();
                    }

                    if (null !== $innerType->getClassName()) {
                        // the builtinType is the inner one and the class is the class followed by []...[]
                        $builtinType = $innerType->getBuiltinType();
                        $class = $innerType->getClassName().$dimensions;
                    } else {
                        // default fallback (keep it as array)
                        $builtinType = $type->getBuiltinType();
                        $class = $type->getClassName();
                    }
                } else {
                    $builtinType = $type->getBuiltinType();
                    $class = $type->getClassName();
                }

                $expectedTypes[Type::BUILTIN_TYPE_OBJECT === $builtinType && $class ? $class : $builtinType] = true;

                if (Type::BUILTIN_TYPE_OBJECT === $builtinType) {
                    if (!$this->serializer instanceof DenormalizerInterface) {
                        throw new LogicException(sprintf('Cannot denormalize attribute "%s" for class "%s" because injected serializer is not a denormalizer.', $attribute, $class));
                    }

                    $childContext = $this->createChildContext($context, $attribute, $format);
                    if ($this->serializer->supportsDenormalization($data, $class, $format, $childContext)) {
                        return $this->serializer->denormalize($data, $class, $format, $childContext);
                    }
                }

                // JSON only has a Number type corresponding to both int and float PHP types.
                // PHP's json_encode, JavaScript's JSON.stringify, Go's json.Marshal as well as most other JSON encoders convert
                // floating-point numbers like 12.0 to 12 (the decimal part is dropped when possible).
                // PHP's json_decode automatically converts Numbers without a decimal part to integers.
                // To circumvent this behavior, integers are converted to floats when denormalizing JSON based formats and when
                // a float is expected.
                if (Type::BUILTIN_TYPE_FLOAT === $builtinType && \is_int($data) && null !== $format && str_contains($format, JsonEncoder::FORMAT)) {
                    return (float) $data;
                }

                if ((Type::BUILTIN_TYPE_FALSE === $builtinType && false === $data) || (Type::BUILTIN_TYPE_TRUE === $builtinType && true === $data)) {
                    return $data;
                }

                if (('is_'.$builtinType)($data)) {
                    return $data;
                }
            } catch (NotNormalizableValueException $e) {
                if (!$isUnionType) {
                    throw $e;
                }
            } catch (ExtraAttributesException $e) {
                if (!$isUnionType) {
                    throw $e;
                }

                $extraAttributesException ??= $e;
            } catch (MissingConstructorArgumentsException $e) {
                if (!$isUnionType) {
                    throw $e;
                }

                $missingConstructorArgumentsException ??= $e;
            }
        }

        if ($extraAttributesException) {
            throw $extraAttributesException;
        }

        if ($missingConstructorArgumentsException) {
            throw $missingConstructorArgumentsException;
        }

        if ($context[self::DISABLE_TYPE_ENFORCEMENT] ?? $this->defaultContext[self::DISABLE_TYPE_ENFORCEMENT] ?? false) {
            return $data;
        }

        throw NotNormalizableValueException::createForUnexpectedDataType(sprintf('The type of the "%s" attribute for class "%s" must be one of "%s" ("%s" given).', $attribute, $currentClass, implode('", "', array_keys($expectedTypes)), get_debug_type($data)), $data, array_keys($expectedTypes), $context['deserialization_path'] ?? $attribute);
    }
}
