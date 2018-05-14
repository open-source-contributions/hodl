<?php
namespace Hodl;

use ReflectionClass;
use ReflectionMethod;
use ReflectionParameter;
use Psr\Container\ContainerInterface;
use Hodl\Exceptions\ContainerException;
use Hodl\Exceptions\NotFoundException;

/**
 * A simple Service container with automatic constructor resolution abilities.
 *
 * A Frankenstein based on Pimple and Laravel Container.
 */
class Container extends ContainerArrayAccess implements ContainerInterface
{
    /**
     * Holds the object storage class.
     * @var Hodl\ObjectStorage
     */
    private $storage;

    /**
     * Stores current resolution stack.
     * @var array
     */
    private $resolutions = [];

    /**
     * Boot up.
     *
     * @since 1.0.0
     */
    public function __construct()
    {
        $this->storage = new ObjectStorage();
    }

    /**
     * Add a class.
     *
     * This class is initialized when first retrieved via get(), and is persistent unless explicitly destroyed.
     *
     * @since 1.0.0
     *
     * @param string   $key     The key to store the object under
     * @param callable $closure A closure which returns a new instance of the desired object
     *                          A reference to this DIContainer is passed as a param to the closure
     */
    public function add(string $key, callable $closure)
    {
        $this->storage->object($key, $closure);
    }

    /**
     * Add a factory class.
     *
     * Classes added via this method will return as a new instance when retrieved.
     *
     * @since 1.0.0
     *
     * @param string   $key     The key to store the object under.
     * @param callable $closure A closure which returns a new instance of the desired object
     *                          A reference to this DIContainer is passed as a param to the closure
     */
    public function addFactory(string $key, callable $closure)
    {
        $this->storage->factory($key, $closure);
    }

    /**
     * Add a specific object instance to the container.
     *
     * @since 1.0.0
     *
     * @param string|object $key    The key to add the instance as. Can be omitted.
     *                              If an object is passed and the key is omitted, the namespaced class name
     *                              will be used instead.
     * @param object        $object The object instance to add.
     */
    public function addInstance($key, $object = null)
    {
        if (is_object($key)) {
            $this->storage->instance(get_class($key), $key);
        } elseif (is_object($object)) {
            $this->storage->instance($key, $object);
        } else {
            throw new ContainerException('An object instance must be passed');
        }
    }

    /**
     * Check if a given key exists within this container, either as an object or a factory.
     *
     * @since 1.0.0
     *
     * @param  string  $key The key to check for.
     * @return boolean      If the key exists.
     */
    public function has($key)
    {
        return $this->storage->hasObject($key) || $this->storage->hasFactory($key);
    }

    /**
     * Retrieves an object for a given key.
     *
     * @since 1.0.0
     *
     * @throws Hodl\Exceptions\ContainerException if the $key is not a valid string.
     * @throws Hodl\Exceptions\NotFoundException  if the $key was not present.
     *
     * @param  string $key  The key to lookup.
     * @return object|bool  The requested object.
     */
    public function get($key)
    {
        if (! is_string($key) || empty($key)) {
            throw new ContainerException('$key must be a string');
        }

        if ($this->storage->hasStored($key)) {
            return $this->storage->getStored($key);
        }

        // key exists but hasn't been initialized yet
        if ($this->storage->hasObject($key)) {
            $this->storage->store($key, $this->storage->getDefinition($key)($this));
            return $this->storage->getStored($key);
        }
        
        if ($this->storage->hasFactory($key)) {
            return $this->storage->getFactory($key);
        }

        // the key was not found
        throw new NotFoundException("The key [$key] could not be found");
    }

    /**
     * Unset a given key, and removes any objects associated with it from the container.
     *
     * @since 1.0.0
     *
     * @param  string $key The key to remove.
     * @return bool        Whether the key and associated object were removed.
     */
    public function remove(string $key)
    {
        return $this->storage->remove($key);
    }

    /**
     * Recursively resolve a given class name via DI.
     *
     * If a key exists within the container it will be injected, otherwise a new instance of the
     * dependency will be injected.
     *
     * For non-resolvable params such as strings etc, they can be set using $args.
     *
     * @since 1.0.0
     *
     * @param  string $class The class to resolve.
     * @param  array  $args  array of arguments to pass to resolved classes as [param name => value].
     * @return object        The resolved class
     */
    public function resolve(string $class, array $args = [])
    {
        $reflectionClass = new ReflectionClass($class);

        // get the constructor method of the current class
        $constructor = $reflectionClass->getConstructor();

        // if there is no constructor, just return new instance
        if ($constructor === null) {
            return new $class;
        }

        // get constructor params
        $params = $constructor->getParameters();

        // If there is a constructor, but no params
        if (count($params) === 0) {
            return new $class;
        }

        foreach ($params as $param) {
            $class = $param->getClass();

            // if the param is not a class, check $args for the value
            if (is_null($class)) {
                $this->resolveParam($args, $param);
                continue;
            }

            $className = $class->getName();

            // if the class exists in the container, inject it
            if ($this->resolveFromContainer($className)) {
                continue;
            }

            // else the param is a class, so run $this->resolve on it
            $this->resolutions[] = $this->resolve($className, $args);
        }

        $resolutions = $this->resolutions;
        $this->resetResolutions();

        // return the resolved class
        return $reflectionClass->newInstanceArgs($resolutions);
    }


    public function resolveMethod($class, string $method, array $args = [])
    {
        if (! is_callable([$class, $method])) {
            throw new ContainerException("$class::$method does not exist or is not callable so could not be resolved");
        }

        $reflectionMethod = new ReflectionMethod($class, $method);
        
        if (is_string($class)) {
            $classInstance = new $class();
        } else {
            $classInstance = $class;
        }

        // get method params
        $params = $reflectionMethod->getParameters();

        // If there is a constructor, but no params
        if (count($params) === 0) {
            // as we are dealing with a static method
            if ($reflectionMethod->isStatic() === true ) {
                return $reflectionMethod->invoke(null);
            } else {
                return $reflectionMethod->invoke($classInstance);
            }
        }

        foreach ($params as $param) {
            $class = $param->getClass();

            // if the param is not a class, check $args for the value
            if (is_null($class)) {
                $this->resolveParam($args, $param);
                continue;
            }

            $className = $class->getName();

            // if the class exists in the container, inject it
            if ($this->resolveFromContainer($className)) {
                continue;
            }

            // else the param is a class, so run $this->resolve on it
            $this->resolutions[] = $this->resolve($className, $args);
        }

        $resolutions = $this->resolutions;
        $this->resetResolutions();

        // return the resolved class
        return $reflectionMethod->invokeArgs($classInstance, $resolutions);
    }

    /**
     * Resets the resolutions stack.
     *
     * @since 1.0.0
     */
    private function resetResolutions()
    {
        $this->resolutions = [];
    }

    /**
     * Searches for a class name in the container, and adds to resolutions if found.
     *
     * @since 1.0.0
     *
     * @param  string $className Key to search for.
     * @return bool              Whether the key was found.
     */
    private function resolveFromContainer(string $className)
    {
        if ($this->has($className)) {
            $this->resolutions[] = $this->get($className);
            return true;
        }
        return false;
    }

    /**
     * Searches for $key in $args, and adds to resolutions if present.
     *
     * @since 1.0.1 Updated to resolve params with default values as a fallback.
     * @since 1.0.0
     *
     * @param  array               $args List of arguments passed to resolve.
     * @param  ReflectionParameter $key  The current parameter reflection class.
     */
    private function resolveParam(array $args, ReflectionParameter $param)
    {
        $name = $param->name;

        if (isset($args[$name])) {
            $this->resolutions[] = $args[$name];
            return;
        }
        if ($param->isDefaultValueAvailable()) {
            $this->resolutions[] = $param->getDefaultValue();
        }
    }
}
