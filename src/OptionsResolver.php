<?php

/**
 * (c) Mehdi Ghezal <mehdi.ghezal@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Jsg\Odoo;


/**
 * @author  Mehdi Ghezal <mehdi.ghezal@gmail.com>
 */
class OptionsResolver
{
    /**
     * @var \Symfony\Component\OptionsResolver\OptionsResolver
     */
    protected $resolver;

    /**
     * @var array
     */
    protected $defaultOptions;

    /**
     * OptionsResolver constructor.
     *
     * @param array $defaultOptions
     */
    public function __construct(array $defaultOptions = [])
    {
        $this->defaultOptions = $defaultOptions;

        $this->resolver = new \Symfony\Component\OptionsResolver\OptionsResolver();
        $this->resolver->setDefined('context')->setAllowedTypes('context', 'array');
    }

    /**
     * @param array $options
     * @return array
     */
    public function resolve(array $options)
    {
        $defaults = [];

        foreach ($this->resolver->getDefinedOptions() as $name) {
            if (isset($this->defaultOptions[$name])) {
                $defaults[$name] = $this->defaultOptions[$name];
            }
        }

        return $this->resolver->setDefaults($defaults)->resolve($options);
    }

    /**
     * @param array $options
     * @return $this
     */
    public function resolveDefaults(array $options)
    {
        $this
            ->registerOffsetOptions()
            ->registerLimitOptions()
            ->registerOrderOptions()
            ->registerFieldsOptions()
            ->registerDomainOptions()
            ->registerLazyOptions()
        ;

        $defaults = [];

        foreach ($this->resolver->getDefinedOptions() as $name) {
            if (isset($this->defaultOptions[$name])) {
                $defaults[$name] = $this->defaultOptions[$name];
            }
        }

        return $this->resolver->setDefaults($defaults)->resolve($options);
    }

    /**
     * @return $this
     */
    public function registerModelOptions()
    {
        $this->resolver
            ->setDefined('model')
            ->setAllowedTypes('model', 'string')
        ;

        return $this;
    }

    /**
     * @return $this
     */
    public function requiredModelOptions()
    {
        $this->resolver->setRequired('model');

        return $this;
    }

    /**
     * @return $this
     */
    public function registerReportOptions()
    {
        $this->resolver
            ->setDefined('report')
            ->setAllowedTypes('report', 'string')
        ;

        return $this;
    }

    /**
     * @return $this
     */
    public function requiredReportOptions()
    {
        $this->resolver->setRequired('report');

        return $this;
    }

    /**
     * @return $this
     */
    public function registerDomainOptions()
    {
        $this->resolver
            ->setDefined('domain')
            ->setAllowedTypes('domain', 'array')
        ;

        return $this;
    }

    /**
     * @return $this
     */
    public function requiredDomainOptions()
    {
        $this->resolver->setRequired('domain');

        return $this;
    }

    /**
     * @return $this
     */
    public function registerDataOptions()
    {
        $this->resolver
            ->setDefined('data')
            ->setAllowedTypes('data', 'array')
        ;

        return $this;
    }

    /**
     * @return $this
     */
    public function requiredDataOptions()
    {
        $this->resolver->setRequired('data');

        return $this;
    }

    /**
     * @return $this
     */
    public function registerIdsOptions()
    {
        $this->resolver
            ->setDefined('ids')
            ->setAllowedTypes('ids', 'array')

            // For Symfony >= 3.4, we can do it with $resolver->setAllowedTypes('ids', 'int[]');
            ->setAllowedValues('ids', function (array $ids) {
                foreach($ids as $item) {
                    if (! is_int($item)) {
                        return false;
                    }
                }

                return true;
            })
        ;

        return $this;
    }

    /**
     * @return $this
     */
    public function requiredIdsOptions()
    {
        $this->resolver->setRequired('ids');

        return $this;
    }

    /**
     * @return $this
     */
    public function registerOffsetOptions()
    {
        $this->resolver
            ->setDefined('offset')
            ->setAllowedTypes('offset', 'int')
        ;

        return $this;
    }

    /**
     * @return $this
     */
    public function requiredOffsetOptions()
    {
        $this->resolver->setRequired('offset');

        return $this;
    }

    /**
     * @return $this
     */
    public function registerLimitOptions()
    {
        $this->resolver
            ->setDefined('limit')
            ->setAllowedTypes('limit', ['int', 'boolean'])

            // For Symfony >= 3.4, we can do it with $resolver->setAllowedTypes('fields', 'string[]');
            ->setAllowedValues('limit', function ($limit) {
                return $limit !== true;
            })
        ;

        return $this;
    }

    /**
     * @return $this
     */
    public function requiredLimitOptions()
    {
        $this->resolver->setRequired('limit');

        return $this;
    }

    /**
     * @return $this
     */
    public function registerOrderOptions()
    {
        $this->resolver
            ->setDefined('order')
            ->setAllowedTypes('order', 'string')
        ;

        return $this;
    }

    /**
     * @return $this
     */
    public function requiredOrderOptions()
    {
        $this->resolver->setRequired('order');

        return $this;
    }

    /**
     * @return $this
     */
    public function registerFieldsOptions()
    {
        $this->resolver
            ->setDefined('fields')
            ->setAllowedTypes('fields', 'array')

            // For Symfony >= 3.4, we can do it with $resolver->setAllowedTypes('fields', 'string[]');
            ->setAllowedValues('fields', function (array $fields) {
                foreach($fields as $item) {
                    if (! is_string($item)) {
                        return false;
                    }
                }

                return true;
            })
        ;

        return $this;
    }

    /**
     * @return $this
     */
    public function requiredFieldsOptions()
    {
        $this->resolver->setRequired('fields');

        return $this;
    }

    /**
     * @return $this
     */
    public function registerGroupByOptions()
    {
        $this->resolver
            ->setDefined('groupBy')
            ->setAllowedTypes('groupBy', 'array')

            // For Symfony >= 3.4, we can do it with $resolver->setAllowedTypes('groupBy', 'string[]');
            ->setAllowedValues('groupBy', function (array $groupBy) {
                foreach($groupBy as $item) {
                    if (! is_string($item)) {
                        return false;
                    }
                }

                return true;
            })
        ;

        return $this;
    }

    /**
     * @return $this
     */
    public function requiredGroupByOptions()
    {
        $this->resolver->setRequired('groupBy');

        return $this;
    }

    /**
     * @return $this
     */
    public function registerLazyOptions()
    {
        $this->resolver
            ->setDefined('lazy')
            ->setAllowedTypes('lazy', 'bool')
        ;

        return $this;
    }

    /**
     * @return $this
     */
    public function requiredLazyOptions()
    {
        $this->resolver->setRequired('lazy');

        return $this;
    }
}
