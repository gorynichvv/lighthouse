<?php

namespace Nuwave\Lighthouse\Schema;

use GraphQL\Language\AST\Node;
use Symfony\Component\Finder\Finder;
use GraphQL\Language\AST\DirectiveNode;
use Symfony\Component\Finder\SplFileInfo;
use GraphQL\Language\AST\FieldDefinitionNode;
use GraphQL\Language\AST\InputValueDefinitionNode;
use GraphQL\Language\AST\TypeSystemDefinitionNode;
use Nuwave\Lighthouse\Support\Contracts\Directive;
use Nuwave\Lighthouse\Exceptions\DirectiveException;
use Nuwave\Lighthouse\Support\Contracts\NodeResolver;
use Nuwave\Lighthouse\Schema\Directives\BaseDirective;
use Nuwave\Lighthouse\Support\Contracts\ArgMiddleware;
use Nuwave\Lighthouse\Support\Contracts\FieldResolver;
use Nuwave\Lighthouse\Support\Contracts\ArgManipulator;
use Nuwave\Lighthouse\Support\Contracts\NodeMiddleware;
use Nuwave\Lighthouse\Support\Contracts\FieldMiddleware;
use Nuwave\Lighthouse\Support\Contracts\NodeManipulator;
use Nuwave\Lighthouse\Support\Contracts\FieldManipulator;

class DirectiveRegistry
{
    /**
     * Collection of registered directives.
     *
     * @var \Illuminate\Support\Collection
     */
    protected $directives;

    /**
     * Create new instance of the directive container.
     * @throws \ReflectionException
     */
    public function __construct()
    {
        $this->directives = collect();

        // Load built-in directives from the default directory
        $this->load(realpath(__DIR__ . '/Directives/'), 'Nuwave\\Lighthouse\\', \dirname(__DIR__));

        // Load custom directives
        $this->load(config('lighthouse.directives'), app()->getNamespace(), app_path());
    }

    /**
     * Gather all directives from a given directory and register them.
     *
     * Works similar to https://github.com/laravel/framework/blob/5.6/src/Illuminate/Foundation/Console/Kernel.php#L191-L225
     *
     * @param array|string $paths
     * @param string $namespace
     * @param string $projectRootPath
     *
     * @throws \ReflectionException
     */
    public function load($paths, string $namespace, string $projectRootPath)
    {
        $paths = collect($paths)
            ->unique()
            ->filter(function ($path) {
                return is_dir($path);
            })->map(function ($path) {
                return realpath($path);
            })->all();

        if (empty($paths)) {
            return;
        }

        /** @var SplFileInfo $file */
        foreach ((new Finder)->in($paths)->files() as $file) {
            // Cut off the given root path to get the path that is equivalent to the namespace
            $namespaceRelevantPath = str_after(
                $file->getPathname(),
                // Call realpath to resolve relative paths, e.g. /foo/../bar -> /bar
                realpath($pathForRootNamespace) . DIRECTORY_SEPARATOR
            );
            
            $withoutExtension = str_before($namespaceRelevantPath, '.php');
            $fileNamespace = str_replace(DIRECTORY_SEPARATOR, '\\', $withoutExtension);

            $this->tryRegisterClassName($rootNamespace . $fileNamespace);
        }
    }

    /**
     * Register a directive class.
     *
     * @param string $className
     *
     * @throws \ReflectionException
     */
    public function tryRegisterClassName($className)
    {
        $reflection = new \ReflectionClass($className);

        if ($reflection->isInstantiable() && $reflection->isSubclassOf(Directive::class)) {
            $this->register(
                resolve($reflection->getName())
            );
        }
    }

    /**
     * Register a directive.
     *
     * @param Directive $directive
     */
    public function register(Directive $directive)
    {
        $this->directives->put($directive->name(), $directive);
    }

    /**
     * Get directive instance by name.
     *
     * @param string $name
     *
     * @throws DirectiveException
     *
     * @return Directive
     */
    public function get($name)
    {
        $directive = $this->directives->get($name);

        if (! $directive) {
            throw new DirectiveException("No directive has been registered for [{$name}]");
        }

        // Always return a new instance of the directive class to avoid side effects between them
        return resolve(\get_class($directive));
    }

    /**
     * Get directive instance by name.
     *
     * @param string $name
     *
     * @throws DirectiveException
     *
     * @return Directive
     *
     * @deprecated Will be removed in next major release
     */
    public function handler($name)
    {
        return $this->get($name);
    }

    /**
     * Get all directives associated with a node.
     *
     * @param Node $node
     *
     * @return \Illuminate\Support\Collection
     */
    protected function directives(Node $node)
    {
        return collect(data_get($node, 'directives', []))->map(function (DirectiveNode $directive) {
            return $this->get($directive->name->value);
        })->map(function (Directive $directive) use ($node) {
            return $this->hydrate($directive, $node);
        });
    }

    /**
     * @param Node $node
     *
     * @return \Illuminate\Support\Collection
     */
    public function nodeManipulators(Node $node)
    {
        return $this->directives($node)->filter(function (Directive $directive) {
            return $directive instanceof NodeManipulator;
        });
    }

    /**
     * @param FieldDefinitionNode $fieldDefinition
     *
     * @return \Illuminate\Support\Collection
     */
    public function fieldManipulators(FieldDefinitionNode $fieldDefinition)
    {
        return $this->directives($fieldDefinition)->filter(function (Directive $directive) {
            return $directive instanceof FieldManipulator;
        });
    }

    /**
     * @param $inputValueDefinition
     *
     * @return \Illuminate\Support\Collection
     */
    public function argManipulators(InputValueDefinitionNode $inputValueDefinition)
    {
        return $this->directives($inputValueDefinition)->filter(function (Directive $directive) {
            return $directive instanceof ArgManipulator;
        });
    }

    /**
     * Get the node resolver directive for the given type definition.
     *
     * @param Node $node
     *
     * @return NodeResolver
     * @deprecated in favour of nodeResolver()
     */
    public function forNode(Node $node)
    {
        return $this->nodeResolver($node);
    }

    /**
     * Get the node resolver directive for the given type definition.
     *
     * @param Node $node
     *
     * @return NodeResolver
     */
    public function nodeResolver(Node $node)
    {
        $resolvers = $this->directives($node)->filter(function (Directive $directive) {
            return $directive instanceof NodeResolver;
        });

        if ($resolvers->count() > 1) {
            $nodeName = data_get($node, 'name.value');
            throw new DirectiveException("Node $nodeName can only have one NodeResolver directive. Check your schema definition");
        }

        return $resolvers->first();
    }

    /**
     * Check if the given node has a type resolver directive handler assigned to it.
     *
     * @param Node $typeDefinition
     *
     * @return bool
     */
    public function hasNodeResolver(Node $typeDefinition)
    {
        return $this->nodeResolver($typeDefinition) instanceof NodeResolver;
    }

    /**
     * @param FieldDefinitionNode $fieldDefinition
     *
     * @return bool
     */
    public function hasResolver($fieldDefinition)
    {
        return $this->hasFieldResolver($fieldDefinition);
    }

    /**
     * Check if the given field has a field resolver directive handler assigned to it.
     *
     * @param FieldDefinitionNode $fieldDefinition
     *
     * @return bool
     */
    public function hasFieldResolver($fieldDefinition)
    {
        return $this->fieldResolver($fieldDefinition) instanceof FieldResolver;
    }

    /**
     * Check if field has a resolver directive.
     *
     * @param FieldDefinitionNode $field
     *
     * @return bool
     */
    public function hasFieldMiddleware($field)
    {
        return collect($field->directives)->map(function (DirectiveNode $directive) {
            return $this->get($directive->name->value);
        })->reduce(function ($has, $handler) {
            return $handler instanceof FieldMiddleware ? true : $has;
        }, false);
    }

    /**
     * Get handler for field.
     *
     * @param FieldDefinitionNode $field
     *
     * @throws DirectiveException
     *
     * @return FieldResolver|null
     */
    public function fieldResolver($field)
    {
        $resolvers = $this->directives($field)->filter(function ($directive) {
            return $directive instanceof FieldResolver;
        });

        if ($resolvers->count() > 1) {
            throw new DirectiveException(sprintf(
                'Fields can only have 1 assigned resolver directive. %s has %s resolver directives [%s]',
                data_get($field, 'name.value'),
                $resolvers->count(),
                collect($field->directives)->map(function (DirectiveNode $directive) {
                    return $directive->name->value;
                })->implode(', ')
            ));
        }

        return $resolvers->first();
    }

    /**
     * Get all middleware directive for a type definitions.
     *
     * @param Node $typeDefinition
     *
     * @return \Illuminate\Support\Collection
     */
    public function nodeMiddleware(Node $typeDefinition)
    {
        return $this->directives($typeDefinition)->filter(function (Directive $directive) {
            return $directive instanceof NodeMiddleware;
        });
    }

    /**
     * Get middleware for field.
     *
     * @param FieldDefinitionNode $fieldDefinition
     *
     * @return \Illuminate\Support\Collection
     */
    public function fieldMiddleware($fieldDefinition)
    {
        return $this->directives($fieldDefinition)->filter(function ($handler) {
            return $handler instanceof FieldMiddleware;
        });
    }

    /**
     * Get middleware for field arguments.
     *
     * @param InputValueDefinitionNode $arg
     *
     * @return \Illuminate\Support\Collection
     */
    public function argMiddleware(InputValueDefinitionNode $arg)
    {
        return $this->directives($arg)->filter(function (Directive $directive) {
            return $directive instanceof ArgMiddleware;
        });
    }

    /**
     * @param Directive                $directive
     * @param TypeSystemDefinitionNode $definitionNode
     *
     * @return Directive
     */
    protected function hydrate(Directive $directive, $definitionNode)
    {
        return $directive instanceof BaseDirective
            ? $directive->hydrate($definitionNode)
            : $directive;
    }
}
