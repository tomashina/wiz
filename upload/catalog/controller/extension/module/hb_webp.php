<?php
require DIR_SYSTEM . 'library/vendor/huntbee-webp/autoload.php';
use WebPConvert\WebPConvert;

class ControllerExtensionModuleHbWebp extends Controller {
    public function cron() {
        $this->load->model('extension/module/hb_webp');
        $this->model_extension_module_hb_webp->addlog('**CRON MODE STARTED**');

        $authkey = $this->request->get['authkey'] ?? ($this->request->get['amp;authkey'] ?? '');

        if (!$this->authenticate($authkey)) {
            die('AUTHORIZATION FAILED');
        }

        $paths = $this->model_extension_module_hb_webp->getUncompressedImages();
        $processed = [];
        $errors = [];

		if (!empty($paths)) {
			foreach ($paths as $path) {
				$id = $path['id'];
				$webp_source = $path['path'];

				list($width_orig, $height_orig, $image_type) = @getimagesize($webp_source);

				if (in_array($image_type, [IMAGETYPE_PNG, IMAGETYPE_JPEG])) {
					$webp_destination = $this->getWebpDestinationPath($webp_source);

					try {
						WebPConvert::convert($webp_source, $webp_destination, []);
						$processed[] = 'Image compressed: ' . $webp_source. ' to ' . $webp_destination;
						$this->model_extension_module_hb_webp->deleteId($id);
					} catch (Exception $e) {
						$errors[] = "Error processing image {$webp_source}: " . $e->getMessage();
					}
				}
			}

			// Log errors
			foreach ($processed as $process) {
				$this->model_extension_module_hb_webp->addlog($process);
			}
		}else{
			$this->model_extension_module_hb_webp->getCachedImages();
		}

        $this->model_extension_module_hb_webp->addlog('**CRON MODE COMPLETED**');
        die('CRON MODE COMPLETED');
    }

    private function authenticate($authkey) {
        if (empty($authkey)) {
            return false;
        }

        $actual_authkey = $this->config->get('hb_webp_cron_key');

        if (!empty($actual_authkey) && $authkey === $actual_authkey) {
            return true;
        }

        $query = $this->db->query("SELECT `value` FROM `" . DB_PREFIX . "setting` WHERE `code` = 'hb_webp' AND `key` = 'hb_webp_cron_key'");

        foreach ($query->rows as $row) {
            if (!empty($row['value']) && $authkey === $row['value']) {
                return true;
            }
        }

        return false;
    }

    private function getWebpDestinationPath($sourcePath) {
        $basePath = str_replace(['\\cache\\', '/cache/'], [DIRECTORY_SEPARATOR . 'webp' . DIRECTORY_SEPARATOR, '/webp/'], $sourcePath);
        $withoutExtension = substr($basePath, 0, strrpos($basePath, '.'));
        return $withoutExtension . '.webp';
    }
}
