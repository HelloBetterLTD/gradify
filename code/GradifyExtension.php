<?php

/**
 * Created by Nivanka Fonseka (nivanka@silverstripers.com).
 * User: nivankafonseka
 * Date: 3/27/17
 * Time: 3:25 PM
 * To change this template use File | Settings | File Templates.
 */
class GradifyExtension extends DataExtension
{

	private static $db = array(
		'GradifyCache'			=> 'Text'
	);

	private static $sensitivity = 7;
	private static $bw_sensitivity = 4;
	private static $ignore_colors = array(
		'0,0,0',
		'255,255,255'
	);

	private $browserPrefixes = ["","-webkit-", "-moz-", "-o-", "-ms-"];

	private $directionMap = array(
		'0'			=> 'right top',
		'270'		=> 'right bottom',
		'180'		=> 'left bottom',
		'360'		=> 'right top',
		'90'		=> 'left top',
	);


	private $resource = null;

	public function Gradify()
	{
		$image = $this->owner;
		if($image->exists()) {
			$cache = $image->GradifyCache;
			$arrGradify = array();
			if ($cache) {
				$arrGradify = unserialize($cache);
			}

			$originalPath = $image->getFullPath();
			$extension = $image->getExtension();
			$key = md5($originalPath);

			if (!isset($arrGradify[$key])) {
				$this->getResource();
				$colors = $this->handleImage();
				$selectColors = $this->selectColors($colors);
				$quads = $this->getQuads($colors, $selectColors);
				$css = $this->makeCSS($quads);

				$arrGradify[$key] = $css;
				$this->owner->GradifyCache = serialize($arrGradify);
				$this->owner->write();
			}

			return $arrGradify[$key];
		}

	}



	public function setImageResource($resource)
	{
		$this->resource = $resource;
	}

	public function makeCSS($quads)
	{
		$css = array();
		$compiled = array();
		foreach ($this->browserPrefixes as $prefix) {
			$arr = array();
			foreach($quads as $quad) {
				$color = $quad[0];
				$angle = $quad[1];

				if($color) {
					$oddDir = $this->directionMap[(90 + $angle + 180) % 360]; //  "right top";
					$arr[] = $prefix . "linear-gradient({$oddDir}, rgba($color, 0) 0%, rgba($color, 1) 100%)";
				}
			}
			$css[$prefix] = implode(',', $arr);
		}


		$styles = "";
		foreach ($css as $line) {
			$styles .= "background: $line;";
		}

		return $styles; // "<div style='width: 100px; height: 100px; $styles'></div>";

	}

	public function getResource()
	{
		if(!$this->resource) {
			$filename = $this->owner->getFullPath();
			list($width, $height, $type, $attr) = getimagesize($filename);
			switch ($type) {
				case 1:
					if (function_exists('imagecreatefromgif'))
						$this->setImageResource(imagecreatefromgif($filename));
					break;
				case 2:
					if (function_exists('imagecreatefromjpeg'))
						$this->setImageResource(imagecreatefromjpeg($filename));
					break;
				case 3:
					if (function_exists('imagecreatefrompng')) {
						$img = imagecreatefrompng($filename);
						imagesavealpha($img, true); // save alphablending setting (important)
						$this->setImageResource($img);
					}
					break;
			}
		}
		return $this->resource;
	}

	public function getColorDiff($color1, $color2)
	{
		$aColor1 = explode(',', $color1);
		$aColor2 = explode(',', $color2);

		return sqrt(abs(1.4 * sqrt(abs($aColor1[0] - $aColor2[0]))))
			+ 0.8 * sqrt(abs($aColor1[1] - $aColor2[1]))
			+ 0.8 * sqrt(abs($aColor1[2] - $aColor2[2]));
	}


	public function getQuads($colors, $selectColors)
	{
		$quadCombo = array(0,0,0,0);
		$takenPos = array(0,0,0,0);
		// Keep track of most dominated quads for each col.
		$quad = array(
			array(
				array(0, 0),
				array(0, 0),
			),
			array(
				array(0, 0),
				array(0, 0),
			),
			array(
				array(0, 0),
				array(0, 0),
			),
			array(
				array(0, 0),
				array(0, 0),
			)
		);


		$colorCounter = 0;
		foreach($colors as $color => $usage) {
			$selectedCounter = 0;
			foreach($selectColors as $selectedColor) {
				if($this->getColorDiff($color, $selectedColor) < 4.3) {
					$xq = floor(($colorCounter % 60) / 30);
					$yq = round( ($colorCounter) / (60*60) );


					if($xq <= 1 && $yq <= 1) {
						$quad[$selectedCounter][$xq][$yq] += 1;
					}

				}
				$selectedCounter += 1;
			}
			$colorCounter += 1;
		}

		
		$selectedCounter = 0;
		foreach($selectColors as $selectColor) {
			$quadArr = [];
			$quadArr[0] = $quad[$selectedCounter][0][0];
			$quadArr[1] = $quad[$selectedCounter][1][0];
			$quadArr[2] = $quad[$selectedCounter][1][1];
			$quadArr[3] = $quad[$selectedCounter][0][1];
			$found = false;

			$j = 0;
			$k = 0;
			while(!$found && $k < 1000) {
				$max = max($quadArr);
				$choice = array_search($max, $quadArr);

				if ($max == 0) {
					$zeroIndex = array_search(0, $quadCombo);
					$quadCombo[$zeroIndex] = array(
						$selectColor,
						90 * $zeroIndex
					);
					$found = true;
				}
				if ($takenPos[$choice] == 0) {
					$quadCombo[$selectedCounter] = array(
						$selectColor,
						90 * $choice
					);
					$takenPos[$choice] = 1;
					$found = true;
					break;
				}
				else {
					$quadArr[$choice] = 0;
				}
				$k += 1;
			}
			$selectedCounter += 1;
		}

		return $quadCombo;

	}


	public function selectColors($colors)
	{
		$selectedColors = array();
		$flag = false;
        $found = false;
        $diff = null;
        $old = array();
		$sensitivity = Config::inst()->get('GradifyExtension', 'sensitivity');
        $bws = Config::inst()->get('GradifyExtension', 'bw_sensitivity');
        $ignored = Config::inst()->get('GradifyExtension', 'ignore_colors');

		while (count($selectedColors) < 4 && !$found) {
			$selectedColors = array();


			foreach($colors as $color => $count) {
				$acceptableColor = false;

				foreach($ignored as $ignoredColor) {
					if($this->getColorDiff($ignoredColor, $color) < $bws) {
						$acceptableColor = true;
						break;
					}
				}

				foreach($selectedColors as $selectedColor) {
					if($this->getColorDiff($selectedColor, $color) < $sensitivity) {
						$acceptableColor = true;
						break;
					}
				}

				if ($acceptableColor) {
					continue;
				}

				$selectedColors[] = $color;
				if(count($selectedColors) > 3) {
					$found = true;
					break;
				}

			}

			if ($bws > 2) {
				$bws -= 1;
			} else {
				$sensitivity--;
				if ($sensitivity < 0) $found = true;
				$bws = Config::inst()->get('GradifyExtension', 'bw_sensitivity');
			}

		}

		return $selectedColors;
	}

	public function handleImage()
	{
		$resource = $this->getResource();
		$colors = array();
		for($x = 0; $x < $this->owner->getWidth(); $x++) {
			for($y = 0; $y < $this->owner->getHeight(); $y++) {
				$color = imagecolorat($resource, $x, $y);
				$r = ($color >> 16) & 0xFF;
				$g = ($color >> 8) & 0xFF;
				$b = $color & 0xFF;
				$rgb = "{$r},{$g},{$b}";
				if(isset($colors[$rgb])){
					$colors[$rgb] += 1;
				}
				else {
					$colors[$rgb] = 0;
				}

				if(count($colors) == 2000) {
					break;
				}

			}
			if(count($colors) == 2000) {
				break;
			}
		}
		arsort($colors);
		return $colors;
	}

}