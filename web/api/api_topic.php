<?php
/**
 * Global Types:
 * TopicId, PostId = string()
 * UserId = int()
 **/

function _topic_has_access($pdo, $topic_id) {
  $stmt = $pdo->prepare('SELECT COUNT(*) cnt FROM topic_readers r WHERE r.user_id = ? AND r.topic_id = ?');
  $stmt->execute(array(ctx_getuserid(), $topic_id));
  $result = $stmt->fetchAll();
  return $result[0]['cnt'] > 0;
}

/**
 * Returns the topic object which is specified by the parameter 'id'. The client must be
 * authenticated and a member of the topic.
 *
 * The resulting object contains two lists of users: Readers and Writers.
 * The readers are the users that currently belong to a topic. Any user can modify this list.
 * The writers are all users that have ever written anything into a topic. This is intended as a help
 * for the UI to be able to show the user info for posts of users, that no longer belong to a post.
 *
 * Input = {'id': TopicId}
 * Result = {'id':TopicId, 'readers': [User], 'messages': [Message], 'writers': [User], 'posts': [Post]}
 *
 * User = {'id': UserId, 'name': string(), 'online': int(),
 *         'email': string(), 'img': string()}
 *
 * Message = {'message_id': Int, 'data': Object}
 *
 * Post = {'id': PostId, 'content':string(), 'revision_no': int(),
 *         'parent': PostId, 'timestamp': int(), 'deleted': int(),
 *         'unread': int()}
 *
 *
 */
function topic_get_details($params) {
  $self_user_id = ctx_getuserid();
  $topic_id = $params['id'];

  ValidationService::validate_not_empty($self_user_id);
  ValidationService::validate_not_empty($topic_id);

  if (!_topic_has_access(ctx_getpdo(), $topic_id)) {
    throw new Exception('Illegal Access!');
  }

  $pdo = ctx_getpdo();

  $readers = TopicRepository::getReaders($topic_id);
  $writers = TopicRepository::getWriters($topic_id);

  $stmt = $pdo->prepare('SELECT p.post_id id, p.content, p.revision_no revision_no,
      p.parent_post_id parent, p.last_touch timestamp, p.deleted deleted,
      p.intended_post intended_post,
      coalesce((select 0 from post_users_read
            where topic_id = p.topic_id AND post_id = p.post_id AND user_id = ?), 1) unread
     FROM posts p
    WHERE p.topic_id = ?
    ORDER BY created_at');
  $stmt->execute(array($self_user_id, $topic_id));
  $posts = $stmt->fetchAll();

  $stmt = $pdo->prepare('SELECT e.user_id id FROM post_editors e WHERE topic_id = ? AND post_id = ?');
  foreach($posts AS $i => $post) {
    # Integer formatting for JSON-RPC result
    $posts[$i]['timestamp'] = intval($posts[$i]['timestamp']);
    $posts[$i]['revision_no'] = intval($posts[$i]['revision_no']);
    $posts[$i]['deleted'] = intval($posts[$i]['deleted']);
    $posts[$i]['unread'] = intval($posts[$i]['unread']);
		$posts[$i]['intended_post'] = intval($posts[$i]['intended_post']);

    $posts[$i]['locked'] = TopicRepository::getPostLockStatus($topic_id, $posts[$i]['id']);
    if ($posts[$i]['locked']['user_id'] == $self_user_id) {
      $posts[$i]['locked'] = NULL;
    }

    # Subobject
    $posts[$i]['users'] = array();
    $stmt->execute(array($topic_id, $post['id']));
    foreach($stmt->fetchAll() AS $post_user) {
      $posts[$i]['users'][] = intval($post_user['id']);
    }
  }

  // Archived?
  $archived = UserArchivedTopicRepository::isArchivedTopic($self_user_id, $topic_id);

  return array (
    'id' => $topic_id,
    'readers' => $readers,
    'messages' => TopicMessagesRepository::listMessages($topic_id, $self_user_id),
    'writers' => $writers,
    'posts' => $posts,
    'archived' => $archived
  );
}

/**
 * Adds a user to the specified topic. The user must be authenticated and a reader of the specified topic.
 * Raises an error if not a member
 *
 * input = {'topic_id': TopicId, 'contact_id': UserId}
 * result = true
 */
function topic_add_user($params) {
  $self_user_id = ctx_getuserid();
  $topic_id = $params['topic_id'];
  $user_id = $params['contact_id'];

  ValidationService::validate_not_empty($self_user_id);
  ValidationService::validate_not_empty($topic_id);
  ValidationService::validate_not_empty($user_id);

  $pdo = ctx_getpdo();
  if ( _topic_has_access($pdo, $topic_id) ) {
    $topic_user = UserRepository::get($user_id);

    foreach(TopicRepository::getReaders($topic_id) as $reader) {
      NotificationRepository::push($reader['id'], array(
        'type' => 'topic_changed',
        'topic_id' => $topic_id
      ));

      # No message for the acting user
      if ($reader['id'] !== $self_user_id) {
        TopicMessagesRepository::createMessage(
          $topic_id,
          $reader['id'],
          array(
            'type' => 'user_added',
            'user_id' => $user_id,
            'user_name' => $topic_user['name']
          )
        );

        # Move topic back to inbox, if changed
        UserArchivedTopicRepository::setArchived($reader['id'], $topic_id, 0);
      }

    }

    # Now add the user
    TopicRepository::addReader($topic_id, $user_id);

    # And notify him
    NotificationRepository::push($user_id, array(
      'type' => 'topic_changed',
      'topic_id' => $topic_id
    ));

    # NOTE: No need to mark all posts as unread for the new user, as we only store
    #       the 'read' status, no unread messages.

    return TRUE;
  }
  else {
    throw new Exception('Illegal Access!');
  }
}

/**
 * Removes a user from a topic. Any read/unread message status or other user information regarding the
 * topic are immediately destroyed.
 *
 * E.g. if the user gets later readded to the topic, all posts are marked as unread.
 *
 * The client must be authenticated and a reader of the topic.
 * An exception is raised, if the client is not a member.
 *
 * input = {'topic_id': TopicId, 'contact_id': UserId}
 * result = TRUE
 */
function topic_remove_user($params) {
  $self_user_id = ctx_getuserid();
  $topic_id = $params['topic_id'];
  $user_id = $params['contact_id'];

  ValidationService::validate_not_empty($topic_id);
  ValidationService::validate_not_empty($user_id);

  $pdo = ctx_getpdo();
  if ( _topic_has_access($pdo, $topic_id) ) {
    $topic_user = UserRepository::get($user_id);

    foreach(TopicRepository::getReaders($topic_id) as $reader) {
      NotificationRepository::push($reader['id'], array(
        'type' => 'topic_changed',
        'topic_id' => $topic_id
      ));

      # Do not create a message for the acting nor the actual removed user
      if ($self_user_id !== $reader['id'] && $reader['id'] !== $user_id) {
        TopicMessagesRepository::createMessage(
          $topic_id,
          $reader['id'],
          array(
            'type' => 'user_removed',
            'user_id' => $user_id,
            'user_name' => $topic_user['name']
          )
        );
      }
      # Move topic back to inbox, if changed
      UserArchivedTopicRepository::setArchived($reader['id'], $topic_id, 0);
    }

    # Delete afterwards. The other way around, the deleted user wouldn't get the notification
    TopicRepository::removeReader($topic_id, $user_id);

    return TRUE;
  }
  else {
    throw new Exception('Illegal Access!');
  }
}

/**
 * Creates a new post as a child in the given topic. The post is created for the current
 * user and has an empty content. A notification is sent to all readers of the topic to inform
 * them about the new post.
 *
 * The client must be authenticated and a reader of the given topic.
 *
 * input = {'topic_id': TopicId, 'post_id': PostId, 'parent_post_id': PostId}
 * result = true
 */
function post_create($params) {
  $self_user_id = ctx_getuserid();
  $topic_id = $params['topic_id'];
  $post_id = $params['post_id'];
  $parent_post_id = $params['parent_post_id'];
  $intended_reply = $params['intended_reply'];

  ValidationService::validate_not_empty($topic_id);
  ValidationService::validate_not_empty($post_id);
  ValidationService::validate_not_empty($parent_post_id);
  ValidationService::validate_list($intended_reply, array('0', '1'));

  $pdo = ctx_getpdo();

  if ( _topic_has_access($pdo, $topic_id) ) {
    TopicRepository::createPost($topic_id, $post_id, $self_user_id, $parent_post_id, $intended_reply);

    TopicRepository::setPostLockStatus($topic_id, $post_id, 1, $self_user_id);

    foreach(TopicRepository::getReaders($topic_id) as $reader) {
      NotificationRepository::push($reader['id'], array(
        'type' => 'topic_changed',
        'topic_id' => $topic_id
      ));

      # Move topic back to inbox, if changed
      UserArchivedTopicRepository::setArchived($user['id'], $topic_id, 0);
    }

    # Mark unread for author
    TopicRepository::setPostReadStatus(
      $self_user_id, $topic_id, $post_id, 1
    );

    return TRUE;
  }
  else {
    throw new Exception('Illegal Access!');
  }
}

/**
 * Changes the content of a post. The revision_no must match the current revision number,
 * otherwise an exception will be thrown. This prevents overwritting changes of other users.
 * The new revision_no is returned.
 *
 * Upon a change, the read status of the post for all other users will resetted. Also a 'post_changed'
 * notification will be generated for all other readers.
 *
 * The client must be authenticated and the user must be a reader of the topic.
 *
 * input = {'topic_id': TopicId, 'post_id': PostId, 'content': string(), 'revision_no': int()}
 * result = {'revision_no': int()}
 */
function post_edit($params) {
  $self_user_id = ctx_getuserid();
  $topic_id = $params['topic_id'];
  $post_id = $params['post_id'];
  $content = $params['content'];
  $revision = $params['revision_no'];

  ValidationService::validate_not_empty($self_user_id);
  ValidationService::validate_not_empty($topic_id);
  ValidationService::validate_not_empty($post_id);
  ValidationService::validate_not_empty($revision);
  ValidationService::validate_content($content);

  $pdo = ctx_getpdo();

  if ( _topic_has_access($pdo, $topic_id) ) {
    $stmt = $pdo->prepare('SELECT revision_no, content FROM posts WHERE topic_id = ? AND post_id = ?');
    $stmt->execute(array($topic_id, $post_id));
    $posts = $stmt->fetchAll();

    if ( sizeof($posts) === 0 ) {
      # Post has already been deleted. Toooo laggy? No idea...
      return NULL;
    }

    # RevisionNo must match (to prevent accidental overwrites)
    if ($posts[0]['revision_no'] != $revision) {
      throw new Exception('RevisionNo is not correct. Somebody else changed the post already. (Value: ' . $posts[0]['revision_no'] . ')');
    }

    # Check if there is a lock
    $lock = TopicRepository::getPostLockStatus($topic_id, $post_id);
    if ($lock !== NULL && $lock["user_id"] !== $self_user_id) {
      throw new Exception("This post is locked. You don't own the lock on this post!");
    }

    # Sanitize input
    $content = InputSanitizer::sanitizePostContent($content);

    $pdo->prepare('UPDATE posts SET content = ?, revision_no = revision_no + 1, last_touch = unix_timestamp() WHERE post_id = ? AND topic_id = ?')->execute(array($content, $post_id, $topic_id));
    $pdo->prepare('REPLACE post_editors (topic_id, post_id, user_id) VALUES (?,?,?)')->execute(array($topic_id, $post_id, $self_user_id));

    TopicRepository::setPostLockStatus(
      $topic_id, $post_id, 0, $self_user_id # Clear the lock
    );

    if ( $posts[0]['content'] !== $content) {
      # Mark only as unread, if there were real changes
      TopicRepository::setPostReadStatus(
        $self_user_id, $topic_id, $post_id, 1 # Mark post as read for requesting user
      );
    }

    foreach(TopicRepository::getReaders($topic_id) as $reader) {
      # Notify all readers about the edit
      NotificationRepository::push($reader['id'], array(
        'type' => 'post_changed',
        'topic_id' => $topic_id,
        'post_id' => $post_id
      ));

      # If the content actually changed, mark the post as unread.
      if ($posts[0]['content'] !== $content) {
        TopicRepository::setPostReadStatus(
          $reader['id'], $topic_id, $post_id, 0
        );
      }

      # Move topic back to inbox, if changed
      UserArchivedTopicRepository::setArchived($reader['id'], $topic_id, 0);
    }

    TopicRepository::setPostReadStatus(
      $self_user_id, $topic_id, $post_id, 1 # Mark post as read for requesting user
    );

    return array (
      'revision_no' => ($revision + 1)
    );
  }
  else {
    throw new Exception('Illegal Access!');
  }
}

/**
 * Marks the post as deleted, if it has children. Otherwise delete the post completly.
 * This is due the tree-like arrangement of the posts, so one can only delete a post really from the
 * storage, if it has no children which refer to it.
 *
 * The client must be authenticated and the user must be a reader of the topic.
 *
 * input = {'topic_id': TopicId, 'post_id': PostId}
 * result = true
 */
function post_delete($params) {
  $self_user_id = ctx_getuserid();
  $topic_id = $params['topic_id'];
  $post_id = $params['post_id'];

  ValidationService::validate_not_empty($self_user_id);
  ValidationService::validate_not_empty($topic_id);
  ValidationService::validate_not_empty($post_id);
  ValidationService::check($post_id != '1', 'Root posts cannot be deleted!');

  $pdo = ctx_getpdo();

  if ( _topic_has_access($pdo, $topic_id) ) {
    $stmt = $pdo->prepare('DELETE FROM post_editors WHERE topic_id = ? AND post_id = ?');
    $stmt->execute(array($topic_id, $post_id));

    $pdo->prepare('UPDATE posts SET deleted = 1, content = NULL WHERE topic_id = ? AND post_id = ?')->execute(array($topic_id, $post_id));

    $pdo->prepare('DELETE FROM post_users_read WHERE topic_id = ? AND post_id = ?')->execute(array($topic_id, $post_id));

    TopicRepository::setPostLockStatus($topic_id, $post_id, 0, $self_user_id);

    TopicRepository::deletePostsIfNoChilds($topic_id, $post_id); # Traverses upwards and deletes all posts, if no child exist

    foreach(TopicRepository::getReaders($topic_id) as $user) {
      NotificationRepository::push($user['id'], array(
        'type' => 'post_deleted',
        'topic_id' => $topic_id,
        'post_id' => $post_id
      ));

      # Move topic back to inbox, if changed
      UserArchivedTopicRepository::setArchived($user['id'], $topic_id, 0);
    }
    return TRUE;
  } else {
    throw new Exception('Illegal Access!');
  }
}

/**
 * Sets the (un)read status of a post.
 *
 * The client must be authenticated and the user must be a reader of the topic.
 *
 * input = {'topic_id': TopicId, 'post_id': PostId, 'read': 0|1}
 * result = true
 */
function post_change_read($params) {
  $user_id = ctx_getuserid();
  $topic_id = $params['topic_id'];
  $post_id = $params['post_id'];
  $read = $params['read'];
  $pdo = ctx_getpdo();

  ValidationService::validate_not_empty($user_id);
  ValidationService::validate_not_empty($topic_id);
  ValidationService::validate_not_empty($post_id);
  ValidationService::validate_not_empty($read);

  if ( _topic_has_access($pdo, $topic_id) ) {
    TopicRepository::setPostReadStatus($user_id, $topic_id, $post_id, $read);
  } else {
    throw new Exception('Illegal Access!');
  }
  return TRUE;
}

/**
 * Creates or deletes a lock owned by the current user for the given post. Returns true
 * if the lock status was changed, false if it remains as before (no change).
 *
 * Input = {'topic_id': TopicId, 'post_id': PostId, 'user_id': UserId, 'lock': 1|0}
 *
 * Result = true|false
 */
function post_change_lock($params) {
  $user_id = ctx_getuserid();
  $topic_id = $params['topic_id'];
  $post_id = $params['post_id'];
  $lock = $params['lock'];

  ValidationService::validate_not_empty($user_id);
  ValidationService::validate_not_empty($topic_id);
  ValidationService::validate_not_empty($post_id);
  ValidationService::validate_not_empty($lock);

  $current_lock = TopicRepository::getPostLockStatus($topic_id, $post_id);

  # Allow the lock to be changed, when there is no lock or the lock is owner by the current user
  if ($current_lock == NULL || $current_lock['user_id'] === $user_id) {
    TopicRepository::setPostLockStatus($topic_id, $post_id, $lock, $user_id);

    # Notify other readers, that this post is locked now
    foreach(TopicRepository::getReaders($topic_id) as $user) {
      NotificationRepository::push($user['id'], array(
        'type' => 'post_changed',
        'topic_id' => $topic_id,
        'post_id' => $post_id,
        'source' => 'post_change_lock'
      ));
    }
    return TRUE;
  }
  return FALSE;
}

/**
 * Removes the specified message from the given topic.
 *
 * input = {'topic_id': TopicId, 'message_id': MessageId}
 * output = true
 */
function topic_remove_message($params) {
      $user_id = ctx_getuserid();
      $topic_id = $params['topic_id'];
      $message_id = $params['message_id'];
      $pdo = ctx_getpdo();

      ValidationService::validate_not_empty($user_id);
      ValidationService::validate_not_empty($topic_id);
      ValidationService::validate_not_empty($message_id);

      if (_topic_has_access($pdo, $topic_id)) {

          TopicMessagesRepository::deleteMessage($topic_id, $user_id, $message_id);

          # Notify ourself (e.g. other sessions)
          NotificationRepository::push($user_id, array(
            'type' => 'topic_changed',
            'topic_id' => $topic_id
          ));

          return true;
      }

      return false;
}

/**
 * Marks the given topic as archived or not.
 *
 * input = {'topic_id': TopicId, 'archived': Boolean}
 */
function topic_set_archived($params) {
  $user_id = ctx_getuserid();
  $topic_id = $params['topic_id'];
  $archived_flag = $params['archived'];

  ValidationService::validate_not_empty($user_id);
  ValidationService::validate_not_empty($topic_id);
  ValidationService::validate_list($archived_flag, array('1', '0'));

  UserArchivedTopicRepository::setArchived($user_id, $topic_id, $archived_flag);

  # Notify ourself only, so we now our topic changed
  NotificationRepository::push($user_id, array(
    'type' => 'topic_changed',
    'topic_id' => $topic_id
  ));
}
