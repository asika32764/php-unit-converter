<?php

declare(strict_types=1);

namespace Asika\UnitConverter;

use Asika\UnitConverter\Concerns\CalculationTrait;
use Brick\Math\BigDecimal;
use Brick\Math\BigNumber;
use Brick\Math\Exception\RoundingNecessaryException;
use Brick\Math\RoundingMode;

/**
 * @formatter:off
 *
 * @psalm-type SerializeCallback = \Closure(AbstractMeasurement $remainder, array<string, BigDecimal> $sortedUnits): string
 * @psalm-type FormatterCallback = \Closure(BigDecimal $value, string $unit, AbstractMeasurement $converter): string
 * @psalm-type SuffixNormalizerCallback = \Closure(string $suffix, BigDecimal $value, string $unit,  $converter): string
 *
 * @formatter:on
 */
abstract class AbstractMeasurement implements SerializableMeasurementInterface, \Stringable
{
    use CalculationTrait;

    public protected(set) BigDecimal $value {
        get => $this->value;
        set(BigNumber|int|float|string $value) => $this->value = BigDecimal::of($value);
    }

    public protected(set) string $unit;

    abstract public protected(set) string $atomUnit {
        get;
        set;
    }

    abstract public protected(set) string $defaultUnit {
        get;
        set;
    }

    abstract protected array $unitExchanges {
        get;
    }

    public array $availableUnitExchanges {
        get {
            if ($this->availableUnits) {
                return array_intersect_key(
                    $this->unitExchanges,
                    array_flip($this->availableUnits)
                );
            }

            return $this->unitExchanges;
        }
    }

    public string $baseUnit {
        get {
            foreach ($this->availableUnitExchanges as $unit => $rate) {
                if (BigDecimal::of($rate)->isEqualTo(1)) {
                    return $unit;
                }
            }

            throw new \RuntimeException('No base unit found in available unit exchanges.');
        }
    }

    protected ?array $availableUnits = null;

    public mixed $unitNormalizer = null;

    public mixed $suffixFormatter = null;

    public static function from(mixed $value, ?string $asUnit = null): static
    {
        return new static()->withFrom($value, $asUnit);
    }

    public function withFrom(
        mixed $value,
        ?string $asUnit = null,
        ?int $scale = null,
        RoundingMode $roundingMode = RoundingMode::DOWN,
    ): static {
        if (is_string($value) && !is_numeric($value)) {
            return $this->withParse($value, $asUnit, $scale, $roundingMode);
        }

        return $this->with($value, $asUnit);
    }

    public static function parse(
        string $value,
        ?string $asUnit = null,
        ?int $scale = null,
        RoundingMode $roundingMode = RoundingMode::DOWN
    ): static {
        return new static()->withParse($value, $asUnit, $scale, $roundingMode);
    }

    public static function parseToValue(
        string $value,
        ?string $asUnit = null,
        ?int $scale = null,
        RoundingMode $roundingMode = RoundingMode::DOWN
    ): BigDecimal {
        return static::parse($value, $asUnit, $scale, $roundingMode)->value->toBigDecimal();
    }

    public function withParse(
        string $value,
        ?string $asUnit = null,
        ?int $scale = null,
        RoundingMode $roundingMode = RoundingMode::DOWN
    ): static {
        $new = $this->with(0, $this->atomUnit);

        $values = static::parseValue($value);

        $atomValue = BigDecimal::zero();

        foreach ($values as [$val, $unit]) {
            $unit = $this->normalizeUnit($unit);
            $converted = $new->withValue($val, fromUnit: $unit, scale: $scale, roundingMode: $roundingMode);

            $atomValue = $atomValue->plus($converted->value);
        }

        $new = $new->withValue($atomValue);

        $asUnit ??= $this->unit;

        if ($asUnit && $asUnit !== $new->unit) {
            $asUnit = $this->normalizeUnit($asUnit);
            $new = $new->convertTo($asUnit, $scale, $roundingMode);
        }

        return $new;
    }

    public function __construct(mixed $value = 0, ?string $unit = null)
    {
        $this->value = $value;
        $this->unit = $unit ?? $this->defaultUnit;
    }

    public function withAvailableUnits(?array $units): static
    {
        $new = clone $this;
        $new->availableUnits = $units;

        return $new;
    }

    /**
     * A quick convert without creating an instance.
     *
     * @param  mixed         $value
     * @param  string        $fromUnit
     * @param  string        $toUnit
     * @param  int|null      $scale
     * @param  RoundingMode  $roundingMode
     *
     * @return  BigDecimal
     *
     * @throws \Brick\Math\Exception\DivisionByZeroException
     * @throws \Brick\Math\Exception\MathException
     * @throws \Brick\Math\Exception\NumberFormatException
     * @throws \Brick\Math\Exception\RoundingNecessaryException
     */
    public static function convert(
        mixed $value,
        string $fromUnit,
        string $toUnit,
        ?int $scale = null,
        RoundingMode $roundingMode = RoundingMode::DOWN
    ): BigNumber {
        return new static($value, $fromUnit)
            ->convertTo(
                $toUnit,
                $scale,
                $roundingMode
            )->value;
    }

    #[\NoDiscard]
    public function convertTo(
        string $toUnit,
        ?int $scale = null,
        RoundingMode $roundingMode = RoundingMode::DOWN
    ): static {
        $toUnit = $this->normalizeUnit($toUnit);

        if ($toUnit === $this->unit) {
            return $this;
        }

        $new = clone $this;

        if (!$new->value->isZero()) {
            $new->value = $this->convertValue($new->value, $new->unit, $toUnit, $scale, $roundingMode);
        }

        $new->unit = $toUnit;

        return $new;
    }

    protected function convertValue(
        BigDecimal $value,
        string $fromUnit,
        string $toUnit,
        ?int $scale,
        RoundingMode $roundingMode
    ): BigDecimal {
        $fromUnitRate = $this->getUnitExchangeRate($fromUnit)
            ?? throw new \InvalidArgumentException("Unknown base unit: {$fromUnit}");

        $toUnitRate = $this->getUnitExchangeRate($toUnit)
            ?? throw new \InvalidArgumentException("Unknown target unit: {$toUnit}");

        $newValue = BigDecimal::of($value)
            ->multipliedBy($fromUnitRate)
            ->dividedBy($toUnitRate, $scale, $roundingMode);

        if ($scale === null) {
            $newValue = $newValue->stripTrailingZeros();
        }

        return $newValue;
    }

    public function convertToAtomUnit(): static
    {
        return $this->convertTo($this->atomUnit, 0, RoundingMode::DOWN);
    }

    public function convertToBaseUnit(?int $scale, RoundingMode $roundingMode = RoundingMode::DOWN): static
    {
        return $this->convertTo($this->baseUnit, $scale, $roundingMode);
    }

    public function convertToDefaultUnit(?int $scale, RoundingMode $roundingMode = RoundingMode::DOWN): static
    {
        return $this->convertTo($this->defaultUnit, $scale, $roundingMode);
    }

    public function withValue(
        \Closure|BigNumber|int|float|string $value,
        ?string $fromUnit = null,
        ?int $scale = null,
        RoundingMode $roundingMode = RoundingMode::DOWN
    ): static {
        $new = $this->with($value, $fromUnit);

        if ($new->unit !== $this->unit) {
            $new = $new->convertTo($this->unit, $scale, $roundingMode);
        }

        return $new;
    }

    public function withUnit(string $unit): static
    {
        $new = clone $this;
        $new->unit = $this->normalizeUnit($unit);

        return $new;
    }

    public function with(\Closure|BigNumber|int|float|string $value, ?string $unit = null): static
    {
        $new = clone $this;
        $new->unit = $unit ? $this->normalizeUnit($unit) : $this->unit;

        if ($value instanceof \Closure) {
            $value = $value($this->value, $new->unit, $new);
        }

        $new->value = $value;

        return $new;
    }

    /**
     * @param  FormatterCallback|string|null  $suffix
     * @param  string|null                    $unit
     * @param  int|null                       $scale
     * @param  RoundingMode                   $roundingMode
     *
     * @return  string
     *
     * @throws RoundingNecessaryException
     */
    public function format(
        \Closure|string|null $suffix = null,
        ?string $unit = null,
        ?int $scale = null,
        RoundingMode $roundingMode = RoundingMode::DOWN
    ): string {
        if ($unit !== null) {
            $unit = $this->normalizeUnit($unit);
            $new = $this->convertTo($unit, $scale, $roundingMode);
        } else {
            $new = $this;
        }

        $value = $new->value;

        if ($scale !== null) {
            $value = $value->toScale($scale, $roundingMode);
        } else {
            $value = $value->stripTrailingZeros();
        }

        $unit ??= $this->unit;
        $suffix ??= $unit;

        if ($suffix instanceof \Closure) {
            return $suffix($value, $unit, $this);
        }

        if (is_string($suffix) && str_contains($suffix, '%')) {
            return sprintf($suffix, $value);
        }

        $suffix = $this->formatSuffix($suffix, $value, $unit);

        return $value . $suffix;
    }

    public function isZero(): bool
    {
        return $this->value->isZero();
    }

    public function isNegative(): bool
    {
        return $this->value->isNegative();
    }

    /**
     * @param  string  $unit
     *
     * @return  array{ static, static }  A tuple with [extracted, remainder]
     */
    public function withExtract(string $unit): array
    {
        $remainder = clone $this;

        return [$remainder->extract($unit), $remainder];
    }

    protected function extract(string $unit): static
    {
        $rate = $this->with(1, $unit)->convertTo($this->unit)->value;

        /** @var BigDecimal $part */
        $part = $this->value->dividedBy($rate, 0, RoundingMode::DOWN);

        $this->value = $this->value->minus($part->multipliedBy($rate));

        return $this->with($part, $unit);
    }

    public function serialize(?array $units = null): string
    {
        return $this->convertToAtomUnit()
            ->serializeCallback(
                function (self $remainder, array $sortedUnits) use ($units) {
                    if ($units === null) {
                        $units = array_keys($sortedUnits);
                    } else {
                        $units = array_intersect(
                            array_keys($this->getSortedUnitRates()),
                            $units
                        );
                    }

                    foreach ($units as $unit) {
                        $part = $remainder->extract($unit);

                        if (!$part->isZero()) {
                            $text[] = $part->format();
                        }
                    }

                    $formatted = trim(implode(' ', array_filter($text)));

                    return $formatted ?: $this->with(0)->format();
                }
            );
    }

    public function serializeCallback(\Closure $callback): string
    {
        $atomUnit = $this->atomUnit;
        $remainder = $this->convertTo($atomUnit);

        return (string) $callback($remainder, $this->getSortedUnitRates());
    }

    public function withAddedUnitExchangeRate(
        string $unit,
        BigNumber|float|int|string $rate,
        bool $prepend = false
    ): static {
        $new = clone $this;

        if ($prepend) {
            $new->unitExchanges = [
                $unit => $rate,
                ...$new->unitExchanges,
            ];
        } else {
            // If property has get() hook, this way can avoid the indirect modification error.
            $new->unitExchanges = [
                ...$new->unitExchanges,
                $unit => $rate,
            ];
        }

        return $new;
    }

    public function withoutUnitExchangeRate(string $unit): static
    {
        $new = clone $this;
        unset($new->unitExchanges[$unit]);

        return $new;
    }

    public function getUnitExchangeRate(string $unit): ?BigNumber
    {
        $unit = $this->normalizeUnit($unit);

        if (isset($this->availableUnitExchanges[$unit])) {
            return BigDecimal::of($this->availableUnitExchanges[$unit]);
        }

        return null;
    }

    /**
     * @param  array<BigNumber|float|int>  $units
     * @param  string                      $atomUnit
     * @param  string                      $defaultUnit
     *
     * @return  $this
     */
    public function withUnitExchanges(array $units, string $atomUnit, string $defaultUnit): static
    {
        $new = clone $this;
        $new->unitExchanges = $units;
        $new->atomUnit = $atomUnit;
        $new->defaultUnit = $defaultUnit;

        return $new;
    }

    public function to(string $unit, ?int $scale = null, RoundingMode $roundingMode = RoundingMode::DOWN): BigDecimal
    {
        return $this->convertTo($unit, $scale, $roundingMode)
            ->value
            ->toBigDecimal()
            ->stripTrailingZeros();
    }

    /**
     * @param  string  $value
     *
     * @return  array<array{ value: string, unit: string }>
     */
    protected static function parseValue(string $value): array
    {
        $matches = [];
        $currentValue = null;
        $currentUnit = '';

        $tokens = array_filter(array_map('trim', explode(' ', $value)));

        foreach ($tokens as $token) {
            // Handle `1243minutes`
            if (preg_match('/^([\d,.]+)([a-zA-Z][a-zA-Z\d\s\W]*)$/', $token, $match)) {
                if ($currentValue !== null) {
                    if (!trim($currentUnit)) {
                        throw new \InvalidArgumentException("Unexpected numeric token: {$token}");
                    }

                    $matches[] = ['value' => $currentValue, 'unit' => trim($currentUnit)];
                }

                $currentValue = $match[1];
                $currentUnit = $match[2];
            } elseif (is_numeric($token) || preg_match('/^[\d,\.]+$/', $token)) {
                if ($currentValue !== null) {
                    if (!trim($currentUnit)) {
                        throw new \InvalidArgumentException("Unexpected numeric token: {$token}");
                    }

                    $matches[] = ['value' => $currentValue, 'unit' => trim($currentUnit)];
                }

                $currentValue = $token;
                $currentUnit = '';
            } elseif (preg_match('/^[a-zA-Z][a-zA-Z\d\s\W]*$/', $token)) {
                if ($currentValue === null) {
                    throw new \InvalidArgumentException("Unexpected unit token: {$token}");
                }

                // If we have a unit, we can finalize the current match
                $currentUnit .= ' ' . $token;
            } elseif (empty($token)) {
                continue; // Skip empty tokens
            } else {
                throw new \InvalidArgumentException("Invalid token: {$token}");
            }
        }

        if ($currentValue !== null && trim($currentUnit)) {
            $matches[] = ['value' => $currentValue, 'unit' => trim($currentUnit)];
        }

        if (empty($matches)) {
            throw new \InvalidArgumentException("Invalid format: {$value}");
        }

        return array_map(
            static function ($match) {
                $value = $match['value'];

                if (str_contains($value, ',')) {
                    $value = str_replace(',', '', $value);
                }

                return [$value, trim($match['unit'])];
            },
            $matches
        );
    }

    protected function normalizeUnit(string $unit): string
    {
        if ($this->unitNormalizer) {
            $unit = ($this->unitNormalizer)($unit);
        }

        return $unit;
    }

    /**
     * @return  array<string, BigDecimal>
     */
    protected function getSortedUnitRates(): array
    {
        $units = array_map(BigDecimal::of(...), $this->availableUnitExchanges);

        uasort(
            $units,
            static fn(BigDecimal $a, BigDecimal $b) => $b->toFloat() <=> $a->toFloat(),
        );

        return $units;
    }

    public function withUnitNormalizer(?callable $unitNormalizer): static
    {
        $new = clone $this;
        $new->unitNormalizer = $unitNormalizer;

        return $new;
    }

    public function withSuffixFormatter(?callable $suffixNormalizer): static
    {
        $new = clone $this;
        $new->suffixFormatter = $suffixNormalizer;

        return $new;
    }

    protected function formatSuffix(string $suffix, BigDecimal $value, string $unit): string
    {
        return $this->suffixFormatter
            ? ($this->suffixFormatter)($suffix, $value, $unit, $this)
            : $suffix;
    }

    public static function unitConstants(): array
    {
        $ref = new \ReflectionClass(static::class);
        $constants = $ref->getConstants(\ReflectionClassConstant::IS_PUBLIC);

        $returnConstants = [];

        foreach ($constants as $name => $value) {
            if (str_starts_with($name, 'UNIT_')) {
                $returnConstants[strtolower(substr($name, 5))] = $value;
            }
        }

        return $returnConstants;
    }

    public function withDefaultUnit(string $defaultUnit): static
    {
        $new = clone $this;
        $new->defaultUnit = $defaultUnit;

        return $new;
    }

    public function withAtomUnit(string $atomUnit): static
    {
        $new = clone $this;
        $new->atomUnit = $atomUnit;

        return $new;
    }

    public function __toString(): string
    {
        return (string) $this->value->toBigDecimal()->stripTrailingZeros();
    }

    public function __call(string $name, array $args)
    {
        if (str_starts_with($name, 'to')) {
            $unit = strtolower(substr($name, 2));
            $unit = str_replace('_', '', $unit);

            if ($this->getUnitExchangeRate($unit)) {
                return $this->to($unit, ...$args);
            }
        }

        throw new \BadMethodCallException("Method {$name} does not exist.");
    }
}
