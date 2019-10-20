<?php

namespace Lego\Items\Repository\Item;

/**
 * @author: Solaiman Kmail - Bluetd <s.kmail@blue.ps>
 */

use Lego\Core\Mvc\Newsletter\NewsletterEntity;
use Lego\Core\Mvc\Newsletter\NewsletterModelInterface;
use Lego\Core\Support\Arr;
use Phalcon\Mvc\Model;
use Phalcon\Mvc\Model\Behavior\SoftDelete;
use Phalcon\Mvc\Model\Behavior\Timestampable;

class Item extends Model implements ModelInterface, NewsletterModelInterface
{
    protected $baseData = [];
    public $_is_locked = false;
    protected $newsletterEntity;

    /**
     * Initialize item relations and behaviors
     *
     */
    public function initialize()
    {
        /**
         * get related  languages  to this item
         */
        $this->hasMany('source_id',
            'Lego\Items\Repository\Item\Item',
            'source_id',
            [
                'alias'  => 'languages',
                'params' => [
                    'conditions' => 'type != :type: AND type != :type_2:',
                    'bind'       => [
                        'type'   => RepositoryInterface::ITEM_TYPE_REVISION,
                        'type_2' => RepositoryInterface::ITEM_TYPE_SECOND_CATEGORY,
                    ],
                ],
            ]
        );

        $this->hasMany('source_id',
            'Lego\Items\Repository\Item\Item',
            'source_id',
            [
                'alias'  => 'revisions',
                'params' => [
                    'conditions' => 'type = :type:',
                    'bind'       => [
                        'type' => RepositoryInterface::ITEM_TYPE_REVISION,
                    ],
                ],
            ]
        );
        $this->hasMany('source_id',
            'Lego\Items\Repository\Item\Item',
            'source_id',
            [
                'alias'  => 'second_category',
                'params' => [
                    'conditions' => 'type = :type:',
                    'bind'       => [
                        'type' => RepositoryInterface::ITEM_TYPE_SECOND_CATEGORY,
                    ],
                ],
            ]
        );

        $this->hasMany('id',
            'Lego\Auth\Repository\Log\Log',
            'entity_id',
            [
                'alias'  => 'logs',
                'params' => [
                    'conditions' => 'action IN ("items::create","items::update","items::restore_revision")',
                    'bind'       => [

                    ],
                ],
            ]
        );

        $this->belongsTo('source_id',
            'Lego\Items\Repository\Item\Item',
            'source_id',
            [
                'alias'  => 'source_item',
                'params' => [
                    'conditions' => 'type != :type: AND type != :type_2:',
                    'bind'       => [
                        'type'   => RepositoryInterface::ITEM_TYPE_REVISION,
                        'type_2' => RepositoryInterface::ITEM_TYPE_SECOND_CATEGORY,
                    ],
                ],

            ]
        );


        /**
         * get item root category
         *
         */
        $this->belongsTo('root_id',
            'Lego\Items\Repository\Category\Category',
            'source_id',
            [
                'alias'  => 'root',
                'params' => [
                    'conditions' => 'language = :language:',
                    'bind'       => [
                        'language' => $this->_dependencyInjector['translate']->languageCode(),
                    ],
                ],
            ]
        );

        /**
         * get item category
         */
        $this->belongsTo('category_id',
            'Lego\Items\Repository\Category\Category',
            'source_id',
            [
                'alias'  => 'category',
                'params' => [
                    'conditions' => 'language = :language:',
                    'bind'       => [
                        'language' => $this->_dependencyInjector['translate']->languageCode(),
                    ],
                ],
            ]
        );
        /**
         * get user
         */
        $this->belongsTo('user_id',
            'Lego\Auth\Repository\User\User',
            'id',
            [
                'alias' => 'user',
            ]
        );

        /**
         * update create_at filed on create
         */
        $this->addBehavior(new Timestampable([
            'beforeValidationOnCreate' => [
                'field'  => 'created_at',
                'format' => 'Y-m-d H:i:s',
            ],
        ]));

        /**
         * update updated_at field on update action
         */
        $this->addBehavior(new Timestampable([
            'beforeValidationOnUpdate' => [
                'field'  => 'updated_at',
                'format' => 'Y-m-d H:i:s',
            ],
        ]));

    }

    /**
     *
     */
    public function beforeDelete()
    {
        if (in_array($this->type, [RepositoryInterface::ITEM_TYPE_ITEM, RepositoryInterface::ITEM_TYPE_PAGE])) {
            $this->addBehavior(
                new SoftDelete(
                    [
                        'field' => 'status',
                        'value' => RepositoryInterface::STATUS_DELETED,
                    ]
                )
            );
        }
    }

    /**
     * Return related  languages  to this item by provided parameters
     *
     * @param array|null $parameters
     *
     * @return mixed
     */
    public function languages(array $parameters = null)
    {
        return $this->getRelated('languages', $parameters);
    }

    /**
     * Return related revisions to this item by provided parameters
     *
     * @param $language
     * @param array $parameters
     *
     * @return mixed
     */
    public function revisions($language, $parameters = [])
    {

        $condition = Arr::get($parameters, 0);
        $bind = Arr::get($parameters, 'bind', []);
        if (empty($condition)) {
            $condition = 'language = :language:';
        } else {
            $condition = "($condition) AND language = :language:";
        }
        $bind['language'] = $language;

        $parameters['order'] = 'created_at desc';
        $parameters[0] = $condition;
        $parameters['bind'] = $bind;

        return $this->getRelated('revisions', $parameters);
    }

    /**
     * @param $id
     *
     * @return \Lego\Items\Repository\Item\Item[]
     */
    public function revision($id)
    {
        return $this->revisions($this->language, ['id=:id:', 'bind' => ['id' => $id]])->getFirst();
    }

    /**
     * @param null $language
     * @param array $parameters
     *
     * @return Model\ResultsetInterface
     */
    public function secondCategoryItems($language = null, $parameters = [])
    {
        if (is_null($language)) {
            $language = $this->language;
        }
        if (!isset($parameters['bind'])) {
            $parameters['bind'] = [];
        }

        $condition = '';

        if ($language) {
            $condition = 'language = :language:';
            if (isset($parameters[0]) && !empty($parameters[0])) {
                $condition .= ' AND (' . $parameters[0] . ')';
            }

            $parameters['bind']['language'] = $language;
        }
        if (!empty($condition)) {
            $parameters[0] = $condition;
        }
        if (!array_key_exists('order', $parameters)) {
            $parameters['order'] = 'category_id asc';
        }
        return $this->getRelated('second_category', $parameters);

    }

    /**
     * @return string
     */
    public function getSource()
    {
        return 'items';
    }

    /**
     * Return url
     *
     * @param bool $relative
     * @param array $params
     *
     * @return mixed
     */
    public function url($relative = false, $params = [])
    {
        $seoUrl = $this->_dependencyInjector['config']->get('items::items.seo_url');
        $seoUrlWithId = $this->_dependencyInjector['config']->get('items::items.seo_url_with_id');

        $isUrl = filter_var($this->slug, FILTER_VALIDATE_URL);
        if ($isUrl !== false) {
            return $this->slug;
        }

        if ($this->type == RepositoryInterface::ITEM_TYPE_PAGE) {
            $url = $this->_dependencyInjector['url']->get([
                'for'  => 'items::show.root',
                'root' => $this->slug,
            ], $params);
        } else {
            $cacheKey = 'cat_slug_' . $this->root_id . $this->language;
            if ($this->_dependencyInjector['cache']->exists($cacheKey)) {
                $root = $this->_dependencyInjector['cache']->get($cacheKey);
            } else {
                $root = $this->getRelated('root', ['columns' => 'slug']);
                $this->_dependencyInjector['cache']->save($cacheKey, $root);
            }
            if ($seoUrlWithId) {
                $itemSlug = $this->slug . '-' . $this->source_id;
            } else if ($seoUrl) {
                $itemSlug = $this->slug;
                $itemSlug = urlencode($itemSlug);
            } else {
                $itemSlug = $this->source_id;
            }

            $routeName = 'items::show.item';

            if (!$this->isActive()) {
                $routeName = 'items::preview.item';
                $params['preview'] = $this->source_id;
            }

            $url = $this->_dependencyInjector['url']->get([
                'for'  => $routeName,
                'root' => $root->slug,
                'item' => $itemSlug,
            ], $params);
        }

        $language = $this->language;

        if (!$this->category || !$this->category->isMultilingual()) {
            $language = $this->_dependencyInjector['translate']->languageCode();
        }

        $url = $this->_dependencyInjector['translate']->switchUrl($language, $url, $params);

        if ($relative) {
            return parse_url($url, PHP_URL_PATH);
        }

        return $url;

    }

    /**
     * @return bool
     * @throws \Exception
     */
    public function isActive()
    {
        $createdDate = new \DateTime($this->created_at);
        $publishDate = new \DateTime($this->publish_at);
        $date = $this->_dependencyInjector['date']->now();

        if (($createdDate > $date || $publishDate > $date) && $this->_dependencyInjector['config']->get('items::items.allow_future_items', false) == false) {
            return false;
        }

        return $this->status == RepositoryInterface::STATUS_ACTIVE;
    }


    /**
     * @return bool
     */
    public function isPending()
    {
        return $this->status == RepositoryInterface::STATUS_PENDING;
    }

    /**
     * @return bool
     * @throws \Exception
     */
    public function isExpired()
    {
        $publishDate = new \DateTime($this->publish_at);
        $date = $this->_dependencyInjector['date']->now();
        $expireDate = new \DateTime($this->expire_at);

        if ($publishDate > $date || $expireDate < $date) {
            return true;
        }
        return false;

    }

    /**
     * @param $name
     * @param array $options
     *
     * @return mixed
     */
    public function field($name, $options = [])
    {
        $options['entity'] = $this;
        $value = null;
        if (isset($this->{$name})) {
            $value = $this->{$name};
        }
        return $this->_dependencyInjector['custom_field']->out($name, $value, $options);

    }

    /**
     * @param $name
     *
     * @return array|mixed
     */
    public function gridField($name)
    {
        $callBack = $this->_dependencyInjector['config']->get('items::items.grid_fields.' . $name);
        if ($callBack && $callBack instanceof \Closure) {
            return call_user_func($callBack, $name, $this->readAttribute($name), $this->source_id);
        } elseif ($callBack && is_array($callBack) && array_key_exists('callback', $callBack) && $callBack['callback'] instanceof \Closure) {
            $result = [
                'options' => Arr::get($callBack, 'options', []),
                'value'   => call_user_func($callBack['callback'], $name, $this->readAttribute($name), $this->source_id),
            ];
            return $result;
        } elseif ($callBack && is_string($callBack)) {
            return $callBack;
        }
        return $this->field($name);

    }

    /**
     * @param $shortPermission
     *
     * @return bool
     */
    public function can($shortPermission)
    {
        return $this->category->can($shortPermission);
    }


    /**
     * Get model attributes
     *
     * @return array
     */
    public function getAttributes()
    {
        $metaData = $this->getModelsMetaData();
        return $metaData->getAttributes($this);
    }

    /**
     * @return Model\ResultsetInterface
     */
    public function logs()
    {
        $logs = $this->getRelated('logs', ['order' => 'id desc']);
        return $logs;
    }

    /**
     * @return bool
     */
    public function isRevision()
    {
        return $this->type == RepositoryInterface::ITEM_TYPE_REVISION;
    }

    /**
     * @return Model\ResultsetInterface|Item
     */
    public function sourceItem()
    {
        return $this->getRelated('source_item', ['language = :language:', 'bind' => ['language' => $this->language]]);
    }

    /**
     * @return $this
     */
    public function afterFetch()
    {
        if ($this->type == RepositoryInterface::ITEM_TYPE_SECOND_CATEGORY) {
            $this->_is_locked = true;
            $data = $this->sourceItem()->toArray();
            $data['type'] = $this->type;
            unset($data['id'], $data['source_id'], $data['category_id'], $data['root_id']);
            $this->assign($data);
        }
        return $this;
    }

    /**
     * @return $this
     * @throws \Exception
     */
    public function beforeSave()
    {
        if ($this->type == RepositoryInterface::ITEM_TYPE_SECOND_CATEGORY && $this->_is_locked) {
            throw new \Exception('Second category items cannot update directly');
        }
        return $this;
    }

    /**
     * @return $this
     * @throws \Exception
     */
    public function beforeUpdate()
    {
        if ($this->type == RepositoryInterface::ITEM_TYPE_SECOND_CATEGORY && $this->_is_locked) {
            throw new \Exception('Second category items cannot update directly');
        }
        return $this;
    }

    /**
     * @return NewsletterEntity
     * @throws \Exception
     */
    public function toNewsLetter()
    {
        if (!$this->newsletterEntity) {
            $extraAttributes = $this->_dependencyInjector['config']->get('items::newsletter.extra_attributes', []);
            if (!is_array($extraAttributes)) {
                $extraAttributes = [];
            }
            $attributes = [];
            foreach ($extraAttributes as $attribute) {
                $attributes[$attribute] = $this->field($attribute);
            }
            $this->newsletterEntity = new NewsletterEntity($this->id, $this->title, $this->url(), $this->readAttribute('image'), $this->readAttribute('content'), $attributes);
        }
        return $this->newsletterEntity;
    }

}
