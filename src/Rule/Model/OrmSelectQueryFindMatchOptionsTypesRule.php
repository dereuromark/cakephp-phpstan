<?php
declare(strict_types=1);

namespace CakeDC\PHPStan\Rule\Model;

use Cake\ORM\Query\SelectQuery;
use CakeDC\PHPStan\Rule\Traits\ParseClassNameFromArgTrait;
use PhpParser\Node;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Scalar\String_;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\Php\PhpMethodReflection;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleError;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Rules\RuleLevelHelper;
use PHPStan\Type\ObjectType;
use PHPStan\Type\Type;
use PHPStan\Type\VerbosityLevel;

class OrmSelectQueryFindMatchOptionsTypesRule implements Rule
{
    use ParseClassNameFromArgTrait;

    /**
     * @var \PHPStan\Rules\RuleLevelHelper
     */
    protected RuleLevelHelper $ruleLevelHelper;

    /**
     * @var array<string>
     */
    protected array $queryOptionsMap = [
        'select' => 'select',
        'fields' => 'select',
        'conditions' => 'where',
        'where' => 'where',
        'join' => 'join',
        'order' => 'orderBy',
        'orderBy' => 'orderBy',
        'limit' => 'limit',
        'offset' => 'offset',
        'group' => 'groupBy',
        'groupBy' => 'groupBy',
        'having' => 'having',
        'contain' => 'contain',
        'page' => 'page',
    ];

    /**
     * @param \PHPStan\Rules\RuleLevelHelper $ruleLevelHelper
     */
    public function __construct(RuleLevelHelper $ruleLevelHelper)
    {
        $this->ruleLevelHelper = $ruleLevelHelper;
    }

    /**
     * @return string
     */
    public function getNodeType(): string
    {
        return MethodCall::class;
    }

    /**
     * @param \PhpParser\Node $node
     * @param \PHPStan\Analyser\Scope $scope
     * @return array<\PHPStan\Rules\RuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        assert($node instanceof MethodCall);
        $args = $node->getArgs();
        if (!$node->name instanceof Node\Identifier) {
            return [];
        }
        $reference = $scope->getType($node->var)->getReferencedClasses()[0] ?? null;
        if ($reference === null) {
            return [];
        }
        $details = $this->getDetails($reference, $node->name->name, $args);

        if ($details === null || empty($details['options'])) {
            return [];
        }

        $errors = [];
        foreach ($details['options'] as $name => $item) {
            if (isset($this->queryOptionsMap[$name])) {
                $assignedValueType = $scope->getType($item);
                $methodReflection = $this->getTargetMethod($scope, $this->queryOptionsMap[$name]);
                $error = $this->processPropertyTypeCheck(
                    $methodReflection,
                    $assignedValueType,
                    $details,
                    $name
                );
                if ($error) {
                    $errors[] = $error;
                }
            }
        }

        return $errors;
    }

    /**
     * @param string $reference
     * @param string $methodName
     * @param array<\PhpParser\Node\Arg> $args
     * @return array{'options': array<\PhpParser\Node\Expr>, 'reference':string, 'methodName':string}|null
     */
    protected function getDetails(string $reference, string $methodName, array $args): ?array
    {
        if (str_ends_with($reference, 'Table') && $methodName === 'find') {
            $lastOptionPosition = 1;
            $argNamesIgnore = ['type'];
            $options = $this->getOptions($args, $lastOptionPosition, $argNamesIgnore);

            return [
                'options' => $options,
                'reference' => $reference,
                'methodName' => $methodName,
            ];
        }

        return null;
    }

    /**
     * @param \PHPStan\Reflection\Php\PhpMethodReflection $methodReflection
     * @param \PHPStan\Type\Type $assignedValueType
     * @param array{'reference':string, 'methodName':string} $details
     * @param string $property
     * @return \PHPStan\Rules\RuleError|null
     * @throws \PHPStan\ShouldNotHappenException
     */
    protected function processPropertyTypeCheck(
        PhpMethodReflection $methodReflection,
        Type $assignedValueType,
        array $details,
        string $property
    ): ?RuleError {
        $parameter = $methodReflection->getVariants()[0]->getParameters()[0];
        $parameterType = $parameter->getType();
        $accepts = $this->ruleLevelHelper->acceptsWithReason($parameterType, $assignedValueType, true);//@phpstan-ignore-line

        if ($accepts->result) {
            return null;
        }
        $propertyDescription = sprintf(
            'Call to %s::%s with option "%s"',
            $details['reference'],
            $details['methodName'],
            $property
        );
        $verbosityLevel = VerbosityLevel::getRecommendedLevelByType($parameterType, $assignedValueType);

        return RuleErrorBuilder::message(
            sprintf(
                '%s (%s) does not accept %s.',
                $propertyDescription,
                $parameterType->describe($verbosityLevel),
                $assignedValueType->describe($verbosityLevel)
            )
        )
            ->acceptsReasonsTip($accepts->reasons)
            ->identifier('cake.tableGetMatchOptionsTypes.invalidType')
            ->build();
    }

    /**
     * @param \PHPStan\Analyser\Scope $scope
     * @param string $targetMethod
     * @return \PHPStan\Reflection\Php\PhpMethodReflection
     * @throws \PHPStan\Reflection\MissingMethodFromReflectionException
     */
    protected function getTargetMethod(Scope $scope, string $targetMethod): PhpMethodReflection
    {
        $object = new ObjectType(SelectQuery::class);
        $classReflection = $object->getClassReflection();
        assert($classReflection instanceof ClassReflection);
        $methodReflection = $classReflection
            ->getMethod($targetMethod, $scope);
        assert($methodReflection instanceof PhpMethodReflection);

        return $methodReflection;
    }

    /**
     * @param \PhpParser\Node\Arg $arg
     * @param array<string> $notArgsNames
     * @param array<\PhpParser\Node\Expr> $options
     * @return array<\PhpParser\Node\Expr>
     */
    protected function extractOptionsUnpackedArray(Node\Arg $arg, array $notArgsNames, array $options): array
    {
        if ($arg->value instanceof Array_ && $arg->unpack) {
            $options = $this->getOptionsFromArray($arg->value, $notArgsNames, $options);
        }

        return $options;
    }

    /**
     * @param array<\PhpParser\Node\Arg> $args
     * @return array<\PhpParser\Node\Expr>
     */
    protected function getOptions(array $args, int $optionsArgPosition, array $argNamesIgnore): array
    {
        $lastArgPos = $optionsArgPosition;
        $totalArgsMethod = $optionsArgPosition + 1;
        $options = [];
        if (
            count($args) === $totalArgsMethod
            && $args[$lastArgPos]->value instanceof Array_
            && $args[$lastArgPos]->unpack !== true
        ) {
            return $this->getOptionsFromArray($args[$lastArgPos]->value, $argNamesIgnore, $options);
        }
        foreach ($args as $arg) {
            if ($arg->name && !in_array($arg->name->name, $argNamesIgnore)) {
                $options[$arg->name->name] = $arg->value;
            }
            $options = $this->extractOptionsUnpackedArray($arg, $argNamesIgnore, $options);
        }

        return $options;
    }

    /**
     * @param \PhpParser\Node\Expr\Array_ $source
     * @param array<string> $notArgsNames
     * @param array<\PhpParser\Node\Expr> $options
     * @return array<\PhpParser\Node\Expr>
     */
    protected function getOptionsFromArray(Array_ $source, array $notArgsNames, array $options): array
    {
        foreach ($source->items as $item) {
            if (isset($item->key) && $item->key instanceof String_ && !in_array($item->key->value, $notArgsNames)) {
                $options[$item->key->value] = $item->value;
            }
        }

        return $options;
    }
}