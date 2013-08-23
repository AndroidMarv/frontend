<?php
/**
 * Media model.
 * Parent class for media types, i.e. Photo & Video
 *
 * @author Jaisen Mathai <jaisen@jmathai.com>
 */
abstract class Media extends BaseModel
{
  const typePhoto = 'photo';
  const typeVideo = 'video';

  public function __construct()
  {
    parent::__construct();
  }

  public function getMediaType($filename)
  {
    $type = get_mime_type($filename);
    switch ($type)
    {
      case 'image/gif':
      case 'image/jpeg':
      case 'image/pjpeg':
      case 'image/png':
        return self::typePhoto;
      case 'video/mpeg':
      case 'video/mp4':
      case 'video/ogg':
      case 'video/quicktime':
      case 'video/webm':
        return self::typeVideo;
    }
    return false;
  }

  public function isValidMimeType($filename)
  {
    $media_type = $this->getMediaType($filename);
    return in_array($media_type, array(self::typePhoto, self::typeVideo));
  }

  public function prepareAttributes($attributes, $localFile)
  {
    // make sure the defaults are set (this method sets to null if not already set)
    $attributes = $this->requireDefaults($attributes);

    // for photos we read and inject exif/iptc from the file
    $mediaType = $this->getMediaType($localFile);
    $attributes = $this->setExifAttributes($attributes, $localFile, $mediaType);
    $attributes = $this->setIptcAttributes($attributes, $localFile, $mediaType);

    // set all of the date and tag parameters
    $attributes = $this->setDateAttributes($attributes);
    $attributes = $this->setTagAttributes($attributes);

    // check if the underlying file system needs to include any meta data into the db
    $fsExtras = $this->fs->getMetaData($localFile);
    if(!empty($fsExtras))
      $attributes['extraFileSystem'] = $fsExtras;

    $attributes['owner'] = $this->owner;
    $attributes['actor'] = $this->getActor();
    $attributes['size'] = intval(filesize($localFile)/1024);

    // finally we remove attributes which aren't valid
    $attributes = $this->whitelistAttributes($attributes);
    return $attributes;
  }

  public function requireDefaults($attributes)
  {
    $defaults = array(
      'appId' => $this->config->application->appId, 
      'host' => $this->fs->getHost(), 
      'title'=>null, 
      'description'=>null, 
      'tags'=>null, 
      'latitude'=>null, 
      'longitude'=>null,
      'views' => 0,
      'status' => 1,
      'permission' => 0, // TODO
      'license' => ''
    );
    return array_merge($defaults, $attributes);
  }

  protected function setDateAttributes($attributes)
  {
    if(!isset($attributes['dateTaken']) || empty($attributes['dateTaken']))
      $attributes['dateTaken'] = time();

    if(!isset($attributes['dateUploaded']) || empty($attributes['dateUploaded']))
      $attributes['dateUploaded'] = time();

    $attributes['dateTakenDay'] = date('d', $attributes['dateTaken']);
    $attributes['dateTakenMonth'] = date('m', $attributes['dateTaken']);
    $attributes['dateTakenYear'] = date('Y', $attributes['dateTaken']);
    $attributes['dateUploadedDay'] = date('d', $attributes['dateUploaded']);
    $attributes['dateUploadedMonth'] = date('m', $attributes['dateUploaded']);
    $attributes['dateUploadedYear'] = date('Y', $attributes['dateUploaded']);

    return $attributes;
  }

  protected function setExifAttributes($attributes, $localFile, $mediaType)
  {
    if($mediaType !== self::typePhoto)
      return $attributes;

    $allowAutoRotate = isset($attributes['allowAutoRotate']) ? $attributes['allowAutoRotate'] : '1';
    $exif = $this->readExif($localFile, $allowAutoRotate);
    foreach($exif as $key => $value)
    {
      if(!isset($attributes[$key]) && !empty($value))
        $attributes[$key] = $value;
    }

    return $attributes;
  }

  protected function setIptcAttributes($attributes, $localFile, $mediaType)
  {
    if($mediaType !== self::typePhoto)
      return $attributes;

    $tags = '';
    if(isset($attributes['tags']))
      $tags = $attributes['tags'];

    $iptc = $this->readIptc($localFile);
    foreach($iptc as $iptckey => $iptcval)
    {
      if(empty($iptcval))
        continue;

      if($iptckey == 'tags')
        $attributes['tags'] = implode(',', array_unique(array_merge((array)explode(',', $tags), $iptcval)));
      else if(!isset($attributes[$iptckey])) // do not clobber if already in $attributes #1011
        $attributes[$iptckey] = $iptcval;
    }

    return $attributes;
  }

  protected function setTagAttributes($attributes)
  {
    $tagObj = new Tag;
    if($this->config->photos->autoTagWithDate == 1)
    {
      $dateTags = sprintf('%s,%s', date('F', $attributes['dateTaken']), date('Y', $attributes['dateTaken']));
      // TODO see if there's a shortcut for this
      if(!isset($attributes['tags']) || empty($attributes['tags']))
        $attributes['tags'] = $dateTags;
      else
        $attributes['tags'] .= ",{$dateTags}";
    }

    if(isset($attributes['tags']) && !empty($attributes['tags']))
      $attributes['tags'] = $tagObj->sanitizeTagsAsString($attributes['tags']);

    return $attributes;
  }

  protected function whitelistAttributes($attributes)
  {
    $returnAttrs = array();
    $matches = array(
      'actor' => 1,
      'albums'=>1,
      'altitude' => 1,
      'appId' => 1,
      'dateTaken' => 1,
      'dateUploaded' => 1,
      'description' => 1,
      'filenameOriginal' => 1, /* TODO remove in 1.5.0, only used for upgrade */
      'host' => 1,
      'hash' => 1,
      'height' => 1,
      'groups' => 1,
      'id' => 1,
      'key' => 1,
      'latitude' => 1,
      'license' => 1,
      'longitude' => 1,
      'owner' => 1,
      'pathBase' => 1,
      'pathOriginal' => 1,
      'permission' => 1,
      'photo'=>1,
      'rotation'=>1,
      'size' => 1,
      'status' => 1,
      'tags' => 1,
      'title' => 1,
      'views' => 1,
      'width' => 1,
    );
    $patterns = array('exif.*','date.*','extra.*');
    foreach($attributes as $key => $val)
    {
      if(isset($matches[$key]))
      {
        $returnAttrs[$key] = $val;
        continue;
      }

      foreach($patterns as $pattern)
      {
        if(preg_match("/^{$pattern}$/", $key))
        {
          $returnAttrs[$key] = $val;
          continue;
        }
      }
    }
    return $returnAttrs;
  }
}
