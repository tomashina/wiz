<?php
class ModelToolImage extends Model {
	public function resize($filename, $width, $height) {
		if (!is_file(DIR_IMAGE . $filename) || substr(str_replace('\\', '/', realpath(DIR_IMAGE . $filename)), 0, strlen(DIR_IMAGE)) != str_replace('\\', '/', DIR_IMAGE)) {
			return;
		}

		$extension = pathinfo($filename, PATHINFO_EXTENSION);

		$image_old = $filename;
		$image_new = 'cache/' . utf8_substr($filename, 0, utf8_strrpos($filename, '.')) . '-' . (int)$width . 'x' . (int)$height . '.' . $extension;

		if (!is_file(DIR_IMAGE . $image_new) || (filemtime(DIR_IMAGE . $image_old) > filemtime(DIR_IMAGE . $image_new))) {
			list($width_orig, $height_orig, $image_type) = getimagesize(DIR_IMAGE . $image_old);
				 
			if (!in_array($image_type, array(IMAGETYPE_PNG, IMAGETYPE_JPEG, IMAGETYPE_GIF, IMAGETYPE_WEBP))) { 
				if ($this->request->server['HTTPS']) {
					return $this->config->get('config_ssl') . 'image/' . $image_old;
 				} else {
					return $this->config->get('config_url') . 'image/' . $image_old;
				}
			}
						
			$path = '';

			$directories = explode('/', dirname($image_new));

			foreach ($directories as $directory) {
				$path = $path . '/' . $directory;

				if (!is_dir(DIR_IMAGE . $path)) {
					@mkdir(DIR_IMAGE . $path, 0777);
				}
			}

			if ($width_orig != $width || $height_orig != $height) {
				$image = new Image(DIR_IMAGE . $image_old);
				$image->resize($width, $height);
				$image->save(DIR_IMAGE . $image_new);
			} else {
				copy(DIR_IMAGE . $image_old, DIR_IMAGE . $image_new);
			}
		}

		if ($this->supportsWebp()) {
			$webp_image = $this->ensureWebpImage($image_new);

			if ($webp_image) {
				$image_new = $webp_image;
			}
		}
		
		$image_new = str_replace(' ', '%20', $image_new);  // fix bug when attach image on email (gmail.com). it is automatic changing space " " to +
		
		if ($this->request->server['HTTPS']) {
			return $this->config->get('config_ssl') . 'image/' . $image_new;
		} else {
			return $this->config->get('config_url') . 'image/' . $image_new;
		}
	}

	private function supportsWebp() {
		$accept = isset($this->request->server['HTTP_ACCEPT']) ? $this->request->server['HTTP_ACCEPT'] : '';

		if (stripos($accept, 'image/webp') !== false) {
			return true;
		}

		$user_agent = isset($this->request->server['HTTP_USER_AGENT']) ? $this->request->server['HTTP_USER_AGENT'] : '';

		if (preg_match('/Firefox\/([0-9]+)/i', $user_agent, $match)) {
			return (int)$match[1] >= 65;
		}

		if (preg_match('/Edg\/([0-9]+)/i', $user_agent, $match)) {
			return (int)$match[1] >= 18;
		}

		if (preg_match('/(?:Chrome|Chromium|OPR)\/([0-9]+)/i', $user_agent, $match)) {
			return (int)$match[1] >= 32;
		}

		if (preg_match('/Version\/([0-9]+).*Safari/i', $user_agent, $match)) {
			return (int)$match[1] >= 14;
		}

		return false;
	}

	private function ensureWebpImage($image) {
		$webp_image = $this->getWebpImageName($image);

		if (!$webp_image || !is_file(DIR_IMAGE . $image)) {
			return false;
		}

		if (is_file(DIR_IMAGE . $webp_image) && filemtime(DIR_IMAGE . $webp_image) >= filemtime(DIR_IMAGE . $image)) {
			return $webp_image;
		}

		$image_info = @getimagesize(DIR_IMAGE . $image);

		if (!$image_info || !in_array($image_info[2], array(IMAGETYPE_PNG, IMAGETYPE_JPEG))) {
			return false;
		}

		if (!class_exists('WebPConvert\\WebPConvert')) {
			$autoload = DIR_SYSTEM . 'library/vendor/huntbee-webp/autoload.php';

			if (is_file($autoload)) {
				require_once $autoload;
			}
		}

		if (!class_exists('WebPConvert\\WebPConvert')) {
			return false;
		}

		$destination = DIR_IMAGE . $webp_image;
		$destination_dir = dirname($destination);

		if (!is_dir($destination_dir)) {
			@mkdir($destination_dir, 0777, true);
		}

		try {
			if ($image_info[2] == IMAGETYPE_PNG && $this->convertPngToOpaqueWebp(DIR_IMAGE . $image, $destination)) {
				return $webp_image;
			}

			\WebPConvert\WebPConvert::convert(DIR_IMAGE . $image, $destination, array());
		} catch (\Exception $e) {
			$this->log->write('Extension - Huntbee WebP Compression (Auto): Issue while processing (image) path ' . DIR_IMAGE . $image . '. Issue: ' . $e->getMessage());
			return false;
		} catch (\Throwable $e) {
			$this->log->write('Extension - Huntbee WebP Compression (Auto): Issue while processing (image) path ' . DIR_IMAGE . $image . '. Issue: ' . $e->getMessage());
			return false;
		}

		return is_file($destination) ? $webp_image : false;
	}

	private function convertPngToOpaqueWebp($source, $destination) {
		if (!function_exists('imagecreatefrompng') || !function_exists('imagewebp')) {
			return false;
		}

		$image = @imagecreatefrompng($source);

		if (!$image) {
			return false;
		}

		$width = imagesx($image);
		$height = imagesy($image);
		$canvas = imagecreatetruecolor($width, $height);

		if (!$canvas) {
			imagedestroy($image);
			return false;
		}

		$white = imagecolorallocate($canvas, 255, 255, 255);
		imagefilledrectangle($canvas, 0, 0, $width, $height, $white);
		imagealphablending($canvas, true);
		imagesavealpha($canvas, false);
		imagecopy($canvas, $image, 0, 0, 0, 0, $width, $height);

		$result = imagewebp($canvas, $destination, 85);

		imagedestroy($canvas);
		imagedestroy($image);

		return $result && is_file($destination);
	}

	private function getWebpImageName($image) {
		$extension_position = utf8_strrpos($image, '.');

		if ($extension_position === false) {
			return false;
		}

		$webp_image = utf8_substr($image, 0, $extension_position);
		$webp_image = str_replace('cache/', 'webp/', $webp_image);

		return $webp_image . '.webp';
	}
}
