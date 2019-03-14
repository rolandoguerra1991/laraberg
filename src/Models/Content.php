<?php

namespace VanOns\Laraberg\Models;

use Illuminate\Database\Eloquent\Model;
use Embed\Embed;
use Spatie\Async\Pool;

use VanOns\Laraberg\Events\ContentRendered;

class Content extends Model {
  protected $table = 'lb_contents';
  public $embeds;
  private $index;

  public function contentable() {
    return $this->morphTo();
  }

  /**
   * Returns the rendered content of the content
   */
  public function render() {
    $html = $this->rendered_content;
    $html = $this->renderBlocks($html);
    $html = "<div class='gutenberg__content wp-embed-responsive'>$html</div>";
    return $html;
  }

  public function setContent($html) {
    $this->raw_content = $html;
    $this->rendered_content = $this->renderRaw($html);
  }

  /**
   * Renders the HTML of the content object
   */
  public function renderRaw($raw) {
    $html = $raw;
    $html = $this->renderEmbedsParallel($html);
    event(new ContentRendered($this));
    return $html;
  }
  
  public function renderEmbedsParallel($html) {
    $pool = Pool::create()->timeout(10);
    $regex = '/<!-- wp:core-embed\/.*?--><figure class="wp-block-embed.*?".*?<div class="wp-block-embed__wrapper">(.*?)<\/div><\/figure>/';
    preg_match_all($regex, $html, $matches);
    // Get all embeds asynchronuosly
    foreach ($matches[1] as $index => $match) {
      $pool->add(function () use ($index, $match) {
        $embed = Embed::create($match);
        return [ "embed" => $embed, "index" => $index ];
      })->then(function($output) {
        $this->embeds[$output["index"]] = $output["embed"]->code;
      })->catch(function($error) {
        \Log::info(print_r($error));
      })->timeout(function() {
        \Log::info('TIMEOUT');
      });
    }
    $pool->wait();
    \Log::info(print_r($this->embeds, TRUE));
    $this->index = -1;
    $result = preg_replace_callback($regex, function($matches) {
      $this->index++;
      \Log::info($this->index);
      if (array_key_exists($this->index, $this->embeds)) {
        $embed = $this->embeds[$this->index];
        return str_replace('>'.$matches[1].'<', '>'.$embed.'<', $matches[0]);
      } else {
        return $matches[0];
      }
    }, $html);
    return $result;
  }

  public function renderBlocks($html) {
    $regex = '/<!-- wp:block {"ref":(\d*)} \/-->/';
    $result = preg_replace_callback($regex, function($matches) {
      try {
        return $this->renderBlock($matches);
      } catch (Exception $e) {
        return '';
      }
    }, $html);
    return $result;
  }

  public function renderBlock($matches) {
    if ($matches) {
      $content = Block::find($matches[1])->content['raw'];
      $content = $this->renderBlocks($content);
      return $content;
    }
  }

  public function renderEmbeds($html) {
    $regex = '/<!-- wp:core-embed\/.*?--><figure class="wp-block-embed.*?".*?<div class="wp-block-embed__wrapper">(.*?)<\/div><\/figure>/';
    $result = preg_replace_callback($regex, function($matches) {
      $embed = Embed::create($matches[1])->code;
      return str_replace('>'.$matches[1].'<', '>'.$embed.'<', $matches[0]);
    }, $html);
    return $result;
  }
}