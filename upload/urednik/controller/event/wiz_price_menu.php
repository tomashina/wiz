<?php
class ControllerEventWizPriceMenu extends Controller {
    public function index(&$route, &$data) {
        if (empty($data['menus']) || empty($this->session->data['user_token']) ||
            !$this->user->hasPermission('access', 'catalog/product')) {
            return;
        }

        foreach ($data['menus'] as &$menu) {
            if (($menu['id'] ?? '') === 'menu-catalog') {
                array_unshift($menu['children'], [
                    'name' => 'Bazne cijene',
                    'href' => $this->url->link('catalog/product/base_price', 'user_token=' . $this->session->data['user_token'], true),
                    'children' => []
                ]);
                return;
            }
        }
    }
}
