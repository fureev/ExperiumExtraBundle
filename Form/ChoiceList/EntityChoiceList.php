<?php

namespace Experium\ExtraBundle\Form\ChoiceList;

use Symfony\Component\Form\Util\PropertyPath;
use Symfony\Component\Form\Exception\FormException;
use Symfony\Component\Form\Exception\UnexpectedTypeException;
use Symfony\Component\Form\Extension\Core\ChoiceList\ArrayChoiceList;
use Doctrine\ORM\EntityManager;
use Experium\ExtraBundle\Collection\EntityCollection;

class EntityChoiceList extends ArrayChoiceList
{
    /**
     * @var Doctrine\ORM\EntityManager
     */
    private $em;

    /**
     * The entities from which the user can choose
     *
     * This array is either indexed by ID (if the ID is a single field)
     * or by key in the choices array (if the ID consists of multiple fields)
     *
     * This property is initialized by initializeChoices(). It should only
     * be accessed through getEntity() and getEntities().
     *
     * @var Collection
     */
    private $entities = array();

    /**
     * @var Experium\ExtraBundle\Collection\EntityCollection
     */
    private $entityCollection;

    private $callable;

    private $repository;

    public function __construct($em, $class, $entityCollection = null, $callable = null, $choices = array())
    {
        if (!(null === $entityCollection || $entityCollection instanceof EntityCollection || $entityCollection instanceof \Closure)) {
            throw new UnexpectedTypeException($entityCollection, 'Experium\ExtraBundle\Collection\EntityCollection or \Closure');
        }
        $repositoryMethodName = 'get'.$class.'Repository';
        $this->repository = $em->$repositoryMethodName();
        if ($entityCollection instanceof \Closure) {
            $entityCollection = $entityCollection($this->repository);

            if (!$entityCollection instanceof EntityCollection) {
                throw new UnexpectedTypeException($entityCollection, 'Experium\ExtraBundle\Collection\EntityCollection');
            }
        }

        $this->em = $em;
        $this->entityCollection = $entityCollection;

        // The property option defines, which property (path) is used for
        // displaying entities as strings
        if ($callable) {
            $this->callable = $callable;
        }

        parent::__construct($choices);
    }

    /**
     * Initializes the choices and returns them
     *
     * If the entities were passed in the "choices" option, this method
     * does not have any significant overhead. Otherwise, if a query builder
     * was passed in the "query_builder" option, this builder is now used
     * to construct a query which is executed. In the last case, all entities
     * for the underlying class are fetched from the repository.
     *
     * @return array  An array of choices
     */
    protected function load()
    {
        parent::load();

        if ($this->entityCollection) {
            $entities = $this->entityCollection->fetchAll();
        } else {
            $entities = $this->choices;
        }

        $this->choices = array();
        $this->entities = array();

        $this->loadEntities($entities);

        $this->loaded = true;

        return $this->choices;
    }

    /**
     * Convert entities into choices with support for groups
     *
     * The choices are generated from the entities. If the entities have a
     * composite identifier, the choices are indexed using ascending integers.
     * Otherwise the identifiers are used as indices.
     *
     * If the option "property" was passed, the property path in that option
     * is used as option values. Otherwise this method tries to convert
     * objects to strings using __toString().
     *
     */
    private function loadEntities($entities, $group = null)
    {
        foreach ($entities as $key => $entity) {
            if (is_array($entity)) {
                // Entities are in named groups
                $this->loadEntities($entity, $key);
                continue;
            }

            $this->loadEntity($entity, $group);
        }
    }

    private function loadEntity($entity, $group = null)
    {
        if ($this->callable) {
            $callable = $this->callable;
            $value = $callable($entity);
        } else {
            // Otherwise expect a __toString() method in the entity
            if (!method_exists($entity, '__toString')) {
                throw new FormException('Entities passed to the choice field must have a "__toString()" method defined (or you can also override the "callable" option).');
            }

            $value = (string) $entity;
        }

        $id = $entity->getId();

        if (null === $group) {
            // Flat list of choices
            $this->choices[$id] = $value;
        } else {
            // Nested choices
            $this->choices[$group][$id] = $value;
        }

        $this->entities[$id] = $entity;

        $this->choices[$entity->getId()] = $entity;
    }

    /**
     * Returns the according entities for the choices
     *
     * If the choices were not initialized, they are initialized now. This
     * is an expensive operation, except if the entities were passed in the
     * "choices" option.
     *
     * @return array  An array of entities
     */
    public function getEntities()
    {
        if (!$this->loaded) {
            $this->load();
        }

        return $this->entities;
    }

    /**
     * Returns the entity for the given key
     *
     * @param  string $key  Entity ID
     * @return object       The matching entity
     */
    public function getEntity($key)
    {
        if ($this->entityCollection) {
            if (!$this->loaded) {
                $this->load();
            }

            if ($this->entities) {
                return isset($this->entities[$key]) ? $this->entities[$key] : null;
            } else {
                return null;
            }
        } else {
            if ($entity = $this->repository->find($key)) {
                $this->addEntity($entity);
            }

            return $entity;
        }

    }

    private function addEntity($entity)
    {
        if (!$this->loaded) {
            $this->choices[$entity->getId()] = $entity;
        } else {
            $this->loadEntity($entity);
        }

    }
}
