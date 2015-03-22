<?php namespace uk\co\la1tv\website\transformers;

abstract class Transformer {

	public function transformCollection(array $items) {
		return array_map([$this, "transform"], $items);
	}
	
	public abstract function transform($item);
	
}