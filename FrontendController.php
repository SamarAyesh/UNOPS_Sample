<?php

/**
 * @author: Solaiman Kmail - Bluetd <s.kmail@blue.ps>
 */

namespace Lego\Items\Controllers;

use Phalcon\Http\ResponseInterface;
use Phalcon\Mvc\Controller;


class FrontendController extends Controller
{
    protected $previewMode = false;

    /**
     * Items list
     *
     * @param $rootSlug
     * @param $sub
     *
     * @return
     * @throws \Exception
     */
    public function indexAction($rootSlug, $sub = null)
    {
        try {
            $root = $this->findCategoryBySlug($rootSlug);
        } catch (\Exception $e) {
            if (!$sub) {
                return $this->pageView($rootSlug);
            }
        }
        if (!$sub) {
            $category = $root;
        } else {
            $category = $this->findCategoryBySlug($sub);
        }
        if ($category->root_id != $root->source_id) {
            return $this->response->notFound();
        }

        $template = $this->theme->current()->getSetting('template[category][' . $category->source_id . ']');
        if ($template == -1) {
            $template = $this->theme->current()->getSetting('template[category][' . $category->parent->source_id . ']');
        }
        if (!$template) {
            $template = $this->theme->current()->getSetting('template[category][' . $root->source_id . ']');
        }

        $template = 'category/' . $template;

        $eventResponse = new \stdClass;
        $eventResponse->template = null;

        $this->_dependencyInjector['eventsManager']->fire('items::before.handle.category', $category, $eventResponse);

        if ($eventResponse->template) {
            $template = $eventResponse->template;
        }

        if ($this->_dependencyInjector->has('registry')) {
            $this->registry->_category = $category;
            $this->registry->_category_root = $root;
        }

        return $this->view->make($this->theme->templatePath($template), [
            '_category' => $category,
            '_root'     => $root,
        ]);
    }

    /**
     * Item view page
     *
     * @param      $rootSlug
     * @param null $itemId
     *
     * @throws Exception
     * @return string
     */
    public function itemAction($rootSlug, $itemId)
    {
        $root = $this->findCategoryBySlug($rootSlug);

        if ($this->config->get('items::items.seo_url_with_id')) {
            $segments = explode('-', $itemId);

            $id = end($segments);

            array_pop($segments);

            $itemSlug = implode('-', $segments);

            $item = $this->findItemBySource($id, $root);

            if ($itemSlug !== $item->slug) {
                return $this->response->notFound();
            }

        } else if ($this->config->get('items::items')['seo_url']) {
            $item = $this->findItemBySlug($itemId, $root);
        } else {
            $item = $this->findItemBySource($itemId, $root);
        }
        if ($item instanceof ResponseInterface) {
            return $item;
        }
        if (!$root->source_id == $item->root_id) {
            return $this->response->notFound();
        }
        return $this->itemView($root, $item);
    }

    /**
     * Return the rendered item view
     *
     * @param $root
     * @param $item
     *
     * @return mixed
     */
    protected function itemView($root, $item)
    {
        $template = $this->theme->current()->getSetting('template[item][' . $item->category_id . ']');
        if (!$template) {
            $template = $this->theme->current()->getSetting('template[item][' . $root->source_id . ']');
        }

        $template = 'item/' . $template;

        $event = $this->_dependencyInjector['eventsManager']->fire('items::before.handle.item', $item);
        if (is_array($event) && array_key_exists('template', $event)) {
            $template = $event['template'];
        }
        $this->response->setStatusCode(200);

        if ($this->_dependencyInjector->has('registry')) {
            $this->registry->_item = $item;
        }

        $view = $this->view->make($this->theme->templatePath($template), [
            '_item' => $item,
            '_root' => $root,
        ]);

        if ($this->previewMode) {
            $previewHint = $this->view->make('items::preview_hint');
            $view = str_replace('</body>', $previewHint . '</body>', $view);
        }

        return $view;
    }

    /**
     * Return page view
     *
     * @param $rootSlug
     *
     * @return mixed
     */
    private function pageView($rootSlug)
    {

        $item = $this->item->findBySlug($rootSlug, $this->translate->languageCode());

        if (!$this->canViewItem($item)) {
            return $this->response->notFound();
        }

        $root = $this->category->findBySource($item->root_id, $this->translate->languageCode());

        if (!$root || !$root->isActive()) {
            return $this->response->notFound();
        }

        return $this->itemView($root, $item);

    }

}
