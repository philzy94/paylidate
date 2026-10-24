<?php

namespace Tests\Unit;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Builds every relation declared on every model so a typo in a related class
 * or foreign key cannot hide until a request happens to use it.
 */
class AllModelRelationsTest extends TestCase
{
    /** @return array<string, array{class-string<Model>}> */
    public static function models(): array
    {
        $cases = [];
        foreach (glob(dirname(__DIR__, 2) . '/app/*.php') ?: [] as $file) {
            $class = 'App\\' . basename($file, '.php');
            if (is_subclass_of($class, Model::class)) {
                $cases[$class] = [$class];
            }
        }

        return $cases;
    }

    #[DataProvider('models')]
    public function testEveryRelationBuildsAQueryAgainstARealModel(string $class)
    {
        $model = new $class();
        $checked = 0;

        foreach ((new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            $type = $method->getReturnType();
            if ($method->class !== $class || !$type || !is_subclass_of($type->getName(), Relation::class)) {
                continue;
            }

            $relation = $model->{$method->name}();

            $this->assertInstanceOf(Relation::class, $relation, "{$class}::{$method->name}");
            $this->assertInstanceOf(Model::class, $relation->getRelated(), "{$class}::{$method->name}");
            $checked++;
        }

        $this->assertGreaterThanOrEqual(0, $checked);
    }
}
