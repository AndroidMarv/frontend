<?php
/**
  * Album controller for API endpoints
  *
  * @author Jaisen Mathai <jaisen@jmathai.com>
  */
class ApiAlbumController extends ApiBaseController
{
  /**
    * Call the parent constructor
    *
    * @return void
    */
  public function __construct()
  {
    parent::__construct();
    $this->album = new Album;
    $this->user = new User;
  }

  public function create()
  {
    getAuthentication()->requireAuthentication();
    getAuthentication()->requireCrumb();

    $albumId = $this->album->create($_POST);
    if($albumId)
    {
      $albumResp = $this->api->invoke("/{$this->apiVersion}/album/{$albumId}/view.json", EpiRoute::httpGet);
      if($albumResp['code'] == 200)
        return $this->created('Album created', $albumResp['result']);
    }
    return $this->error('Could not add album', false);
  }

  public function delete($id)
  {
    getAuthentication()->requireAuthentication();
    getAuthentication()->requireCrumb();
    $status = $this->album->delete($id);
    if($status)
      return $this->noContent('Album was deleted', true);

    return $this->error('Could not delete album', false);
  }

  public function form()
  {
    $groupsResp = $this->api->invoke('/groups/list.json', EpiRoute::httpGet);
    $groups = $groupsResp['result'];
    $template = $this->template->get(sprintf('%s/manage-album-form.php', $this->config->paths->templates), array('groups' => $groups));;
    return $this->success('Album form', array('markup' => $template));
  }

  public function list_()
  {
    $albums = $this->album->getAlbums();
    if($albums === false)
      return $this->error('Could not retrieve albums', false);
    return $this->success('List of albums', $albums);
  }

  public function updateIndex($albumId, $type, $action)
  {
    getAuthentication()->requireAuthentication();
    getAuthentication()->requireCrumb();
    
    if(!isset($_POST['ids']) || empty($_POST['ids']))
      return $this->error('Please provide ids', false);

    $cnt = array('success' => 0, 'failure' => 0);
    switch($action)
    {
      case 'add':
        $resp = $this->album->addElement($albumId, $type, $_POST['ids']);
        if($resp)
        {
          $album = $this->album->getAlbum($albumId);
          $ids = (array)explode(',', $_POST['ids']);
          $id = array_pop($ids);
          if($id)
          {
            $lastPhotoResp = $this->api->invoke("/photo/{$id}/view.json", EpiRoute::httpGet, array('_GET' => array('generate' => 'true', 'returnSizes' => '100x100,100x100xCR,200x200,200x200xCR')));
            if($lastPhotoResp['code'] === 200)
            {
              // TODO: this clobbers anything that was in `extra` (currently nothing)
              $this->album->update($albumId, array('extra' => array('cover' => $lastPhotoResp['result'])));
            }
          }
        }
        break;
      case 'remove':
        // TODO: check if the cover photo is one of the photos being removed and reset it. See #757
        $resp = $this->album->removeElement($albumId, $type, $_POST['ids']);
        break;
    }

    if(!$resp)
      return $this->error('All items were not updated', false);

    return $this->success('All items updated', true);
  }

  public function update($id)
  {
    getAuthentication()->requireAuthentication();
    getAuthentication()->requireCrumb();

    $status = $this->album->update($id, $_POST);
    if(!$status)
      return $this->error('Could not update album', false);

    return $this->success('Album updated', true);
  }

  public function view($id)
  {
    $album = $this->album->getAlbum($id);
    if($album === false)
      return $this->error('Could not retrieve album', false);
    return $this->success('Album', $album);
  }
}
