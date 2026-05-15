<?php
class ControllerExtensionModuleTfFilterEvent extends Controller {
        private function shouldHandle() {
                if (!empty($this->request->get['_tf_ajax'])) {
                        return true;
                }

                if (!empty($this->request->get['route']) && $this->request->get['route'] == 'product/special') {
                        return true;
                }

                foreach (array_keys((array)$this->request->get) as $key) {
                        if (strpos($key, 'tf_') === 0) {
                                return true;
                        }
                }

                return false;
        }

        private function bootstrapTfFilter() {
                if (!$this->registry->has('tf_filter_bootstrapped')) {
                        $this->load->controller('extension/maza/startup/tf_filter');
                }
        }

        public function getProducts($route, $param) {
                if (!$this->shouldHandle()) {
                        return null;
                }

                $this->bootstrapTfFilter();

                $this->load->model('extension/maza/tf_product');
                
                if(empty($param[0])){
                    return $this->model_extension_maza_tf_product->getProducts();
                } else {
                    return $this->model_extension_maza_tf_product->getProducts($param[0]);
                }
                
        }
        
        public function getTotalProducts($route, $param) {
                if (!$this->shouldHandle()) {
                        return null;
                }

                $this->bootstrapTfFilter();

                $this->load->model('extension/maza/tf_product');
                
                if(empty($param[0])){
                    return $this->model_extension_maza_tf_product->getTotalProducts();
                } else {
                    return $this->model_extension_maza_tf_product->getTotalProducts($param[0]);
                }
                
        }
}
