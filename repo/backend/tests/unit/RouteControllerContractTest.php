<?php
namespace tests\unit;

use PHPUnit\Framework\TestCase;

/**
 * RouteControllerContractTest - Reflection-based test that verifies every
 * route in api.php points to a controller method that actually exists.
 * Fails if any route references a non-existent method.
 */
class RouteControllerContractTest extends TestCase
{
    /**
     * Parse all 'Controller/method' strings from api.php and verify each
     * controller class has that public method.
     */
    public function testAllRouteActionsMapToExistingControllerMethods(): void
    {
        $routeFile = dirname(__DIR__, 2) . '/route/api.php';
        $this->assertFileExists($routeFile, 'route/api.php must exist');

        $content = file_get_contents($routeFile);

        // Extract all 'SomeController/methodName' strings
        preg_match_all("/['\"](\w+Controller)\/(\w+)['\"]/", $content, $matches, PREG_SET_ORDER);
        $this->assertNotEmpty($matches, 'Should find at least one route definition');

        $errors = [];
        foreach ($matches as $match) {
            $controllerName = $match[1];
            $methodName = $match[2];
            $fqcn = 'app\\controller\\' . $controllerName;

            if (!class_exists($fqcn)) {
                $errors[] = "Controller class {$fqcn} does not exist (route: {$controllerName}/{$methodName})";
                continue;
            }

            $ref = new \ReflectionClass($fqcn);
            if (!$ref->hasMethod($methodName)) {
                $errors[] = "Method {$methodName}() does not exist on {$fqcn}";
                continue;
            }

            $method = $ref->getMethod($methodName);
            if (!$method->isPublic()) {
                $errors[] = "Method {$fqcn}::{$methodName}() exists but is not public";
            }
        }

        $this->assertEmpty($errors, "Route→Controller mismatches found:\n" . implode("\n", $errors));
    }

    /**
     * Verify that every route path in api.php is unique per HTTP method.
     */
    public function testNoDuplicateRoutes(): void
    {
        $routeFile = dirname(__DIR__, 2) . '/route/api.php';
        $content = file_get_contents($routeFile);

        preg_match_all("/Route::(get|post|patch|put|delete)\('([^']+)'/", $content, $matches, PREG_SET_ORDER);
        $this->assertNotEmpty($matches);

        $seen = [];
        $duplicates = [];
        foreach ($matches as $match) {
            $key = strtoupper($match[1]) . ' ' . $match[2];
            if (isset($seen[$key])) {
                $duplicates[] = $key;
            }
            $seen[$key] = true;
        }

        // admin/users has GET (list) and POST (create) which is fine
        // Filter only true duplicates (same method + same path)
        $this->assertEmpty($duplicates, "Duplicate routes found: " . implode(', ', $duplicates));
    }
}
