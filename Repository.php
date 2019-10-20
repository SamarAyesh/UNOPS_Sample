<?php

/**
 * @author: Solaiman Kmail - Bluetd <s.kmail@blue.ps>
 */

namespace Lego\Items\Repository\Item;

use Lego\Core\Mvc\Newsletter\NewsletterRepoInterface;
use Lego\Core\Support\Arr;
use Lego\Core\Support\Str;
use Lego\Core\Validation\Exceptions\ValidationException;
use Lego\Items\Forms\Export;
use Lego\Items\Forms\Item as ItemForm;
use Lego\Reporting\Repository\ReportableServiceInterface;
use Lego\Search\Repository\Collection;
use Lego\Search\Repository\SearchInterface;
use Phalcon\DiInterface;
use Phalcon\Mvc\Model\Query\Builder;
use Phalcon\Mvc\Model\ResultsetInterface;
use Phalcon\Paginator\Adapter\QueryBuilder as PaginatorQueryBuilder;
use Phalcon\Validation\Validator\PresenceOf;

/**
 * Class Repository
 *
 * @note The difference between id and source_id is : the id is unique for one language,
 * source_id is a reference in other languages
 *
 * @package Lego\Items\Repository\Item
 */
class Repository implements RepositoryInterface, ReportableServiceInterface, SearchInterface, NewsletterRepoInterface
{

    use SlugTrait;
    use ReportService;

    protected $_dependencyInjector;

    protected $form;

    /**
     * @var Export
     */
    protected $exportForm;

    protected $model = 'Lego\Items\Repository\Item\Item';

    public function __construct(DiInterface $_dependencyInjector)
    {
        $this->_dependencyInjector = $_dependencyInjector;
    }

    /**
     * Find item by id
     *
     * @param $id
     *
     * @return mixed
     */
    public function findById($id)
    {
        $model = $this->getModel();
        return $model::findFirst(
            [
                'source_id = :id: and type != :type: and type != :type_2:',
                'bind' => [
                    'id'     => $id,
                    'type'   => self::ITEM_TYPE_REVISION,
                    'type_2' => self::ITEM_TYPE_SECOND_CATEGORY,
                ],
            ]
        );
    }

    /**
     * @param $id
     *
     * @return \Phalcon\Mvc\Model|Item
     */
    public function findRevisionById($id)
    {
        $model = $this->getModel();
        return $model::findFirst(
            [
                'id = :id: AND type = :type:',
                'bind' => [
                    'id'   => $id,
                    'type' => self::ITEM_TYPE_REVISION,
                ],
            ]
        );

    }

    /**
     * Find item by source id
     *
     * @param $id
     * @param null $language
     *
     * @return mixed
     */
    public function findBySource($id, $language = null)
    {
        if (is_array($id)) {
            $id = array_filter($id, function ($v) {
                return intval($v) != 0;
            });
            $args = [
                'source_id in (' . implode(',', $id) . ')',
            ];
        } else {
            $args = [
                'source_id = :source_id:',
                'bind' => [
                    'source_id' => $id,
                ],
            ];
        }
        if ($language) {
            $args[0] .= ' AND language = :language:';
            $args['bind']['language'] = $language;
        }
        $args[0] .= ' AND type != ' . self::ITEM_TYPE_REVISION;
        $args[0] .= ' AND type != ' . self::ITEM_TYPE_SECOND_CATEGORY;

        $model = $this->getModel();
        if (is_array($id)) {
            return $model::find($args);
        } else {
            return $model::findFirst($args);
        }

    }

    /**
     * Find Item by slug
     *
     * @param $slug
     * @param $language
     *
     * @return mixed
     */
    public function findBySlug($slug, $language = null)
    {
        $args = [
            'slug = :slug: AND type != :type: AND type != :type_2:',
            'bind' => [
                'slug'   => $slug,
                'type'   => self::ITEM_TYPE_REVISION,
                'type_2' => self::ITEM_TYPE_SECOND_CATEGORY,
            ],
        ];
        if ($language) {
            $args[0] .= ' AND language = :language: ';
            $args['bind']['language'] = $language;
        }

        $model = $this->getModel();
        return $model::findFirst($args);
    }

    /**
     * Get paginated items
     *
     * @param $page
     * @param int $perPage
     * @param array $where
     * @param null $appendCondition
     * @param string $order
     *
     * @return mixed
     */
    public function paginate($page, $perPage = 20, array $where = [], $appendCondition = null, $order = 'priority ASC, created_at DESC')
    {

        $builder = $this->_dependencyInjector['modelsManager']->createBuilder()->from($this->getModel());

        $builder->where('language = :language: and status != :deleted_status: and type != :type:', [
            'language'       => $this->_dependencyInjector['translate']->languageCode(),
            'deleted_status' => RepositoryInterface::STATUS_DELETED,
            'type'           => self::ITEM_TYPE_REVISION,
        ]);

        foreach ($where as $col => $value) {
            $builder->andWhere($col . ' = :' . $col . ':', [
                $col => $value,
            ]);
        }

        if ($appendCondition) {
            $builder->andWhere($appendCondition);
        }

        $builder->orderBy($order);
        $builder->groupBy('source_id,language');
        return $this->paginator($builder, $page, $perPage);
    }

    /**
     * Get paginated items
     *
     * @param     $language
     * @param int $category_id
     * @param     $page
     * @param int $perPage
     *
     * @return mixed
     */
    public function paginateByLanguageAndCategory($language, $category_id = null, $page, $perPage = 20)
    {
        $builder = $this->_dependencyInjector['modelsManager']->createBuilder()->from($this->getModel());
        $condition = 'language = :language: and status != :deleted_status: AND type != :type:';
        $params = [
            'language'       => $language,
            'deleted_status' => RepositoryInterface::STATUS_DELETED,
            'type'           => self::ITEM_TYPE_REVISION,
        ];
        if ($category_id && is_numeric($category_id)) {
            $condition .= ' AND category_id = :category_id: ';
            $params['category_id'] = $category_id;
        }
        $builder->where($condition, $params);
        $builder->groupBy('source_id,language');
        return $this->paginator($builder, $page, $perPage);
    }

    /**
     * Get paginated items by category id
     *
     * @param $categoryId
     * @param $page
     * @param int $perPage
     * @param array $where
     * @param bool $multilingual
     *
     * @return mixed
     */
    public function paginateByCategory($categoryId, $page, $perPage = 20, $where = [], $multilingual = true, $order = [])
    {
        /**
         * @var Builder $builder
         */
        $builder = $this->_dependencyInjector['modelsManager']->createBuilder()->from($this->getModel());

        if (!is_array($categoryId)) {
            $categoryId = [$categoryId];
        }

        $condition = ' ( root_id IN ( ' . implode(',', $categoryId) . ' ) OR  category_id IN (' . implode(',', $categoryId) . ') )';

        $bind = [];

        if ($multilingual) {
            $condition .= ' and language = :language: ';
            $bind['language'] = $this->_dependencyInjector['translate']->languageCode();
        } else {
            $builder->groupBy('source_id');
        }
        $condition .= ' AND type != :type: ';
        $bind['type'] = self::ITEM_TYPE_REVISION;

        $builder->where($condition, $bind);

        foreach ($where as $col => $value) {
            $builder->andWhere($col . ' = :' . $col . ':', [
                $col => $value,
            ]);
        }

        $orderBy = $this->_dependencyInjector['config']->get('items::items.dashboard.pagination_order', 'priority ASC, created_at DESC');
        if (count($order) && isset($order['sort']) && !empty($order['sort'])) {
            $modelClass = $this->getModel();
            $model = new $modelClass;
            $attributes = $model->getAttributes();
            if (in_array($order['sort'], $attributes) && in_array(strtolower($order['order']), ['asc', 'desc'])) {
                $orderBy = $order['sort'] . ' ' . $order['order'];
            }
        }

        $builder->orderBy($orderBy);
        $builder->groupBy('source_id,language');
        return $this->paginator($builder, $page, $perPage);
    }

    /**
     * Get paginated trashed items
     *
     * @param     $page
     * @param int $perPage
     *
     * @return mixed
     */
    public function trashed($page, $perPage = 20)
    {
        $builder = $this->_dependencyInjector['modelsManager']->createBuilder()->from($this->getModel());
        $builder->where('language = :language: and status = :deleted_status: and type != :type:', [
            'language'       => $this->_dependencyInjector['translate']->languageCode(),
            'deleted_status' => RepositoryInterface::STATUS_DELETED,
            'type'           => self::ITEM_TYPE_REVISION,
        ]);
        $builder->groupBy('source_id,language');
        return $this->paginator($builder, $page, $perPage);
    }

    /**
     * Create new item
     *
     * @param array $data
     *
     * @return ModelInterface
     * @throws ValidationException
     */
    public function create(array $data)
    {
        if (!isset($data['item']) || !is_array($data['item'])) {
            $data['item'] = [];
        }

        if (!isset($data['item']['category_id'])) {
            $data['item']['category_id'] = null;
        }

        $form = $this->form(null, [
            'category_id' => $data['item']['category_id'],
            'data'        => $data,
        ]);

        $data = $this->generateSlug($data);
        if (!$form->isValid($data)) {
            throw new ValidationException($form->getMessages());
        }

        $source = null;
        $category = $this->_dependencyInjector['category']->findBySource($data['item']['category_id']);
        foreach ($this->_dependencyInjector['translate']->languages() as $code => $language) {
            // if the title is empty then skip the saving ..
            if (empty($data['item_lang'][$code]['title'])) {
                continue;
            }
            if (!array_key_exists('status', $data['item_lang'][$code])) {
                $data['item_lang'][$code]['status'] = RepositoryInterface::STATUS_PENDING;
            }

            if (!$this->canChangeStatus($category, $this->_dependencyInjector['auth']->user()) && in_array($data['item_lang'][$code]['status'], [
                    RepositoryInterface::STATUS_ACTIVE,
                    RepositoryInterface::STATUS_DELETED,
                    RepositoryInterface::STATUS_REJECTED,
                ])) {
                $data['item_lang'][$code]['status'] = RepositoryInterface::STATUS_PENDING;
            }

            $item = new Item;

            $data['item_lang'][$code]['language'] = $code;

            $this->save($item, array_merge_recursive($data['item'], $data['item_lang'][$code]));

            if ($language['active'] == true) {
                $data['item']['source_id'] = $item->id;
                $item->source_id = $item->id;
                $item->save();
                $source = $item;
            }

            $item->refresh();

            $this->_dependencyInjector['eventsManager']->fire('item::saved', $item);
            $this->_dependencyInjector['logger']->log('items::create', [
                'id'        => $item->id,
                'source_id' => $item->source_id,
            ], $item->id);
        }

        if ($source) {
            $this->_dependencyInjector['eventsManager']->fire('item::saved.source', $source);
            $this->_dependencyInjector['eventsManager']->fire('item::create', $source);

            $this->saveSecondCategory($source, Arr::get($data['item'], 'second_category_id', []));

            return $source;
        }
    }

    /**
     * Update item
     *
     * @param ModelInterface $sourceItem
     * @param array $data
     *
     * @return ModelInterface
     * @throws ValidationException
     */
    public function update(ModelInterface $sourceItem, array $data)
    {
        $categoryId = $sourceItem->category_id;
        if (isset($data['item']['category_id'])) {
            $categoryId = $data['item']['category_id'];
        }

        $form = $this->form($sourceItem, [
            'category_id' => $categoryId,
            'data'        => $data,
        ]);

        $data = $this->generateSlug($data, $sourceItem);

        if (!$form->isValid($data) && !isset($data['restored_from_revision'])) {
            throw new ValidationException($form->getMessages());
        }

        if (!isset($data['item']) || !is_array($data['item'])) {
            $data['item'] = [];
        }

        $oldSourceItem = clone $sourceItem;

        $category = $this->_dependencyInjector['category']->findBySource($data['item']['category_id']);
        foreach ($this->_dependencyInjector['translate']->languages() as $code => $language) {

            // if the title is empty then skip  saving ..
            if (empty($data['item_lang'][$code]['title'])) {
                continue;
            }
            if (!array_key_exists('status', $data['item_lang'][$code])) {
                $data['item_lang'][$code]['status'] = RepositoryInterface::STATUS_PENDING;
            }

            if (!$this->canChangeStatus($category, $this->_dependencyInjector['auth']->user())) {
                if ($oldSourceItem && $oldSourceItem->status != RepositoryInterface::STATUS_ACTIVE) {
                    $data['item_lang'][$code]['status'] = $oldSourceItem->status;
                } else {
                    $data['item_lang'][$code]['status'] = RepositoryInterface::STATUS_PENDING;
                }
            }

            $item = $sourceItem->languages([
                'language = :language:',
                'bind' => [
                    'language' => $code,
                ],
            ]);

            if (isset($item[0])) {
                $item = $item[0];
                $create = false;
            } else {
                $item = new Item();
                $create = true;
            }

            $data['item_lang'][$code]['language'] = $code;

            $data['item_lang'][$code]['source_id'] = $sourceItem->source_id;

            $mergedData = array_merge_recursive($data['item'], $data['item_lang'][$code]);
            $diff = [];

            if (!$create) {
                $diff = Arr::diff(array_merge($item->toArray(), $mergedData), $item->toArray());
                $itemAttr = $item->getAttributes();
                foreach (array_keys($diff) as $column) {
                    if (!in_array($column, $itemAttr)) {
                        unset($diff[$column]);
                    }
                }
            }

            $oldItem = clone $item;

            $this->save($item, $mergedData);

            $item->refresh();

            if ($create) {
                $this->_dependencyInjector['logger']->log('items::create', [
                    'id'        => $item->id,
                    'source_id' => $item->source_id,
                ], $item->id);
                $this->_dependencyInjector['eventsManager']->fire('item::update.create', $item);
            } else {
                // Logging when user change a value
                if (count($diff)) {
                    $revision = false;
                    if (!isset($data['restored_from_revision'])) {
                        $revision = $this->saveRevision($oldItem);
                    }

                    $this->_dependencyInjector['logger']->log('items::update', [
                        'id'          => $item->id,
                        'source_id'   => $item->source_id,
                        'revision_id' => $revision ? $revision->id : null,
                        'changes'     => $diff,
                    ], $item->id);
                    $this->_dependencyInjector['eventsManager']->fire('item::update', $item);
                }
            }

            $this->_dependencyInjector['eventsManager']->fire('item::saved', $item, $oldItem);

        }

        $sourceItem->refresh();
        $this->saveSecondCategory($sourceItem, Arr::get($data['item'], 'second_category_id', []));

        $this->_dependencyInjector['eventsManager']->fire('item::saved.source', $sourceItem, $oldSourceItem);
        return $sourceItem;
    }

    public function saveSecondCategory(ModelInterface $sourceItem, array $categoriesIds)
    {
        if (!$this->_dependencyInjector['config']->get('items::items.second_category.enabled', false)) {
            return;
        }
        $categoriesIds = (array)$categoriesIds;
        $categoriesIds = array_unique($categoriesIds);
        $categoriesIds = array_filter($categoriesIds);
        $max = $this->_dependencyInjector['config']->get('items::items.second_category.max', 5);
        $saved = [];

        $categories = [];
        if (!empty($categoriesIds)) {
            $categories = $this->_dependencyInjector['category']->findBySource($categoriesIds);
        }

        foreach ($sourceItem->languages() as $itemLanguage) {

            $secondItems = $itemLanguage->secondCategoryItems($itemLanguage->language);
            if (empty($categoriesIds)) {
                $secondItems->delete();
                continue;
            }

            $current = [];
            foreach ($secondItems as $itemModel) {
                $current[$itemModel->category_id] = $itemModel;
            }

            $loop = 0;

            foreach ($categories as $category) {
                $catLangKey = $category->source_id . $itemLanguage->language;

                if ($itemLanguage->category_id == $category->source_id || $loop >= $max || in_array($catLangKey, $saved)) {
                    continue;
                }
                $loop++;
                if (array_key_exists($category->source_id, $current)) {
                    $model = $current[$category->source_id];
                } else {
                    $modelClass = $this->getModel();
                    $model = new $modelClass;
                }

                $data = [
                    'language'   => $itemLanguage->readAttribute('language'),
                    'title'      => $itemLanguage->readAttribute('title'),
                    'slug'       => $itemLanguage->readAttribute('slug') . '-' . Str::random(),
                    'status'     => $itemLanguage->readAttribute('status'),
                    'priority'   => $itemLanguage->readAttribute('priority'),
                    'publish_at' => $itemLanguage->readAttribute('publish_at'),
                    'expire_at'  => $itemLanguage->readAttribute('expire_at'),
                ];

                $data['source_id'] = $itemLanguage->source_id;
                $data['category_id'] = $category->source_id;
                $data['root_id'] = $category->root_id;

                if ($this->_dependencyInjector['auth']->check()) {
                    $data['user_id'] = $this->_dependencyInjector['auth']->user()->id;
                }
                $data['created_at'] = $data['updated_at'] = date('Y-m-d H:i:s');

                $data['type'] = RepositoryInterface::ITEM_TYPE_SECOND_CATEGORY;
                $customFields = $this->_dependencyInjector['config']->get('items::items.second_category.custom_fields', []);
                $customFields = (array)$customFields;
                foreach ($customFields as $customField) {
                    if (in_array($customField, $itemLanguage->getAttributes())) {
                        $data[$customField] = $itemLanguage->readAttribute($customField);
                    }
                }

                $model->assign($data);
                $model->writeAttribute('_is_locked', false);
                $model->save();
                $saved[$model->id] = $catLangKey;
            }
        }

        if (empty($saved)) {
            $saved[-1] = -1;
        }
        $saved = array_keys($saved);
        $sourceItem->refresh();

        $toDelete = $sourceItem->secondCategoryItems(false, ['id not in (' . implode(',', $saved) . ')']);
        $toDelete->delete();
    }

    /**
     * @param ModelInterface $item
     *
     * @return Item
     */
    protected function saveRevision(ModelInterface $item)
    {
        $data = $item->toArray();

        unset($data['id']);

        $data['type'] = self::ITEM_TYPE_REVISION;

        $revision = new Item();
        $revision->create($data);
        return $revision;
    }

    /**
     * Save item values on create/update
     *
     * @param ModelInterface $item
     * @param array $data
     *
     * @return ModelInterface
     */
    protected function save(ModelInterface $item, array $data)
    {
        $item->title = $data['title'];

        $item->priority = $data['priority'];

        $item->status = $data['status'];

        $item->created_at = $data['created_at'];

        if (isset($data['publish_at']) && !empty($data['publish_at'])) {
            $item->publish_at = $data['publish_at'];
        } else {
            $item->publish_at = null;
        }

        if (isset($data['expire_at']) && !empty($data['expire_at'])) {
            $item->expire_at = $data['expire_at'];
            if (isset($data['disable_expire_at']) && $data['disable_expire_at'] == 1) {
                $item->expire_at = null;
            }
        } else {
            $item->expire_at = null;
        }
        if (isset($data['source_id'])) {
            $item->source_id = $data['source_id'];
        }

        $item->category_id = $data['category_id'];

        $item->root_id = $this->_dependencyInjector['category']->findBySource($data['category_id'])->root_id;

        $item->language = $data['language'];

        if ((!isset($item->user_id) || !$item->user_id) && $this->_dependencyInjector['auth']->check()) {
            $item->user_id = $this->_dependencyInjector['auth']->user()->id;
        }

        if ($item->root && $item->root->slug == 'pages') {
            $item->type = self::ITEM_TYPE_PAGE;
        } else {
            $item->type = self::ITEM_TYPE_ITEM;
        }

        if (!isset($data['created_at']) && (!isset($item->created_at) || !$item->created_at)) {
            $item->created_at = $this->_dependencyInjector['date']->timestamp();
        }

        $item->updated_at = $this->_dependencyInjector['date']->timestamp();
        $item->slug = $data['slug'];

        $item->save();

        $item->refresh();

        $this->saveItemCustomFields($item, $data);

        $itemBySlug = $this->findBySlug($item->slug, $item->language);

        if ($itemBySlug && $itemBySlug->id != $item->id) {
            if ($item->source_id) {
                $item->slug = $this->appendIdToSlug($item->slug, $item->source_id);
            } else {
                $item->slug = $this->appendIdToSlug($item->slug, $item->id);
            }
        }
        $item->save();
        return $item;
    }

    /**
     * Delete item
     *
     * @param ModelInterface $item
     * @param array $deletedLanguages
     *
     * @return bool
     */
    public function delete(ModelInterface $item, array $deletedLanguages = [])
    {
        foreach ($item->languages() as $language) {
            if (in_array($language->language, $deletedLanguages)) {
                $this->_dependencyInjector['logger']->log('items::delete', [
                    'id'        => $language->id,
                    'source_id' => $language->source_id,
                    'title'     => $language->title,
                ], $language->id);
                $language->delete();
            }
        }
    }

    /**
     * Return new instance of item form
     *
     * @param       $entity
     * @param array $userOptions
     *
     * @return \Phalcon\Forms\Form ;
     */
    public function form($entity = null, array $userOptions = [])
    {
        if (!$this->form) {
            $this->form = $this->generateForm($entity, $userOptions);
        }
        return $this->form;
    }

    /**
     * save item custom fields
     *
     * @param $item
     * @param $data
     */
    private function saveItemCustomFields($item, $data)
    {

        $itemFields = $this->form->fieldsIDs();

        if (!is_array($itemFields) || !count($itemFields)) {
            return;
        }

        $fields = $this->_dependencyInjector['custom_field']->findFieldsById($itemFields);

        foreach ($fields as $field) {
            $customField = $this->_dependencyInjector['custom_field']->getFieldTypeInstance($field->type);
            if (!isset($data[$field->code])) {
                $data[$field->code] = null;
            }
            $customField->save($field, $item, $data[$field->code]);
        }
    }

    /**
     * Get new instance of item form
     *
     * @param       $entity
     * @param array $userOptions
     *
     * @return ItemForm
     */
    public function generateForm($entity, array $userOptions = [])
    {
        return new ItemForm($entity, $userOptions);
    }

    /**
     * Get list of pages from a category
     *
     * @param $categoryId
     * @param $language
     *
     * @return ResultsetInterface
     */
    public function pages($categoryId, $language)
    {
        $model = $this->getModel();
        return $model::find([
            'category_id = :category_id: and language = :language: and language = :language: AND type = :type:',
            'bind' => [
                'category_id' => $categoryId,
                'language'    => $language,
                'status'      => self::STATUS_ACTIVE,
                'type'        => self::ITEM_TYPE_PAGE,
            ],
        ]);
    }


    /**
     * Return paginator
     *
     * @param     $builder
     * @param int $page
     * @param int $limit
     *
     * @return \stdClass
     */
    protected function paginator($builder, $page = 1, $limit = 20)
    {
        return (new PaginatorQueryBuilder([
            "builder" => $builder,
            "limit"   => $limit,
            "page"    => $page,
        ]))->getPaginate();
    }

    /**
     *
     * Items to export
     *
     * @param $categoryId
     * @param array $data
     *
     * @return ResultsetInterface
     */
    public function export($categoryId, array $data)
    {
        $conditions = [];
        $bind = [];
        $data = array_filter($data);
        $data['language'] = Arr::get($data, 'language', $this->_dependencyInjector['translate']->languageCode());

        $conditions[] = "(category_id = :category_id: OR root_id = :root_id:)";
        $bind['category_id'] = $categoryId;
        $bind['root_id'] = $categoryId;

        $conditions[] = "(DATE(created_at) >= :from_date: and DATE(created_at) <= :to_date:)";
        $bind['from_date'] = Arr::get($data, 'from_date', date('Y-m-d'));
        $bind['to_date'] = Arr::get($data, 'to_date', date('Y-m-d'));
        unset($data['from_date'], $data['to_date']);

        foreach ($data as $field => $value) {
            $key = Str::slug(uniqid($field . '_'), '_');
            $conditions[] = "$field = :$key:";
            $bind[$key] = $value;
        }
        $types = [
            RepositoryInterface::ITEM_TYPE_PAGE,
            RepositoryInterface::ITEM_TYPE_ITEM,
            RepositoryInterface::ITEM_TYPE_SECOND_CATEGORY,
        ];

        $conditions[] = ' (type IN (' . implode(',', $types) . ')) ';

        $model = $this->getModel();
        return $model::find([
            implode(' AND ', $conditions),
            'bind' => $bind,
        ]);
    }

    /**
     * Get instance of Export Form
     *
     * @param null $entity
     * @param array $userOptions
     *
     * @return Export
     */
    public function exportForm($entity = null, array $userOptions = [])
    {
        if (!$this->exportForm) {
            $this->exportForm = new Export($entity, $userOptions);
        }
        return $this->exportForm;
    }

    /**
     * Return list of status list in the system
     */
    public function statusList()
    {
        return $this->_dependencyInjector['translate']->_('items::labels.status_types');
    }

    /**
     * @param $model
     *
     * @return $this
     */
    public function setModel($model)
    {
        $this->model = $model;
        return $this;
    }

    /**
     * @return mixed
     */
    public function getModel()
    {
        if (!$this->model) {
            $this->model = $this->_dependencyInjector['config']->get('items::items.model', Item::class);
        }
        return $this->model;
    }

    /**
     * @param $keyword
     *
     * @return mixed
     */
    public function newsLetterSearch($keyword)
    {
        $types = [
            self::ITEM_TYPE_ITEM,
            self::ITEM_TYPE_PAGE,
        ];
        $bind = [];
        $conditions = 'title LIKE :title: AND (type IN(' . implode(',', $types) . ')) AND status = :status:';
        $bind['title'] = "%$keyword%";
        $bind['status'] = self::STATUS_ACTIVE;
        $model = $this->getModel();
        return $model::find([$conditions, 'bind' => $bind, 'limit' => 20]);
    }

    /**
     * @param $ids
     *
     * @return \Phalcon\Mvc\Model|ResultsetInterface
     */
    public function findByIds($ids)
    {
        $ids = (array)$ids;

        $ids = array_filter($ids, function ($v) {
            return intval($v) != 0;
        });
        if (empty($ids)) {
            $ids[] = -1;
        }
        $args = [
            'id in (' . implode(',', $ids) . ')',
        ];
        $args[0] .= ' AND type != ' . self::ITEM_TYPE_REVISION;
        $args[0] .= ' AND type != ' . self::ITEM_TYPE_SECOND_CATEGORY;

        $model = $this->getModel();

        return $model::find($args);

    }

    public function canChangeStatus($model, $user = null)
    {
        if (!$this->_dependencyInjector['auth']->check() || !$user) {
            return false;
        } else if (
            $user->isSuper()
            || (!$this->_dependencyInjector['config']->get('items::permissions.auto_pending_items', false) && $user->can('items::can_change_status'))
            || ($this->_dependencyInjector['config']->get('items::permissions.auto_pending_items', false) && $model->can('can_change_status'))
        ) {
            return true;
        }

        return false;
    }

}
