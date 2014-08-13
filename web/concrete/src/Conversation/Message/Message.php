<?php
namespace Concrete\Core\Conversation\Message;

use Concrete\Core\Conversation\FlagType\FlagType;
use Concrete\Core\Conversation\Rating\Type;
use File;
use Core;
use Loader;
use Conversation;
use ConversationEditor;
use \Concrete\Core\Foundation\Object;
use User;
use UserInfo;

class Message extends Object implements \Concrete\Core\Permission\ObjectInterface
{
    public function getConversationMessageID() {return $this->cnvMessageID;}
    public function getConversationMessageSubject() {return $this->cnvMessageSubject;}
    public function getConversationMessageBody() {return $this->cnvMessageBody;}
    public function getConversationID() {return $this->cnvID;}
    public function getConversationEditorID() {return $this->cnvEditorID;}
    public function getConversationMessageLevel() {return $this->cnvMessageLevel;}
    public function getConversationMessageParentID() {return $this->cnvMessageParentID;}
    public function getConversationMessageSubmitIP() {return long2ip($this->cnvMessageSubmitIP);}
    public function getConversationMessageSubmitUserAgent() { return $this->cnvMessageSubmitUserAgent;}
    public function isConversationMessageDeleted() {return $this->cnvIsMessageDeleted;}
    public function isConversationMessageFlagged() {return (count($this->getConversationMessageFlagTypes()) > 0);}
    public function isConversationMessageApproved() {return $this->cnvIsMessageApproved;}
    public function getConversationMessageFlagTypes()
    {
        $db = Loader::db();
        if ($this->cnvMessageFlagTypes) return $this->cnvMessageFlagTypes;
        $flagTypes = $db->GetCol('SELECT cnvMessageFlagTypeID FROM ConversationFlaggedMessages WHERE cnvMessageID=?',array($this->cnvMessageID));
        $flags = array();
        foreach ($flagTypes as $flagType) {
            $flags[] = FlagType::getByID($flagType);
        }
        $this->cnvMessageFlagTypes = $flags;

        return $flags;
    }
    public function getConversationMessageTotalRatingScore() {return $this->cnvMessageTotalRatingScore;}

    public function getPermissionResponseClassName()
    {
        return '\\Concrete\\Core\\Permission\\Response\\ConversationResponse';
    }

    public function getPermissionAssignmentClassName()
    {
        return '\\Concrete\\Core\\Permission\\Assignment\\ConversationAssignment';
    }

    public function getPermissionObjectKeyCategoryHandle()
    {
        return 'conversation';
    }

    public function getPermissionObjectIdentifier()
    {
        return $this->getConversationMessageID();
    }


    public function conversationMessageHasActiveChildren()
    {
        $db = Loader::db();
        $children = $db->getCol('SELECT cnvMessageID as cnt FROM ConversationMessages WHERE cnvMessageParentID=?',array($this->cnvMessageID));
        foreach ($children as $childID) {
            $child = static::getByID($childID);
            if (($child->isConversationMessageApproved() && !$child->isConversationMessageDeleted()) || $child->conversationMessageHasActiveChildren()) {
                return true;
            }
        }

        return false;
    }

    public function setMessageBody($cnvMessageBody)
    {
        $this->cnvMessageBody = $cnvMessageBody;
        $db = Loader::db();
        $db->Execute('update ConversationMessages set cnvMessageBody = ? where cnvMessageID = ?', array(
                $cnvMessageBody, $this->getConversationMessageID()
        ));
    }

    public function conversationMessageHasChildren()
    {
        $db = Loader::db();
        $count = $db->getOne('SELECT COUNT(cnvMessageID) as cnt FROM ConversationMessages WHERE cnvMessageParentID=?',array($this->cnvMessageID));

        return ($count > 0);
    }
    public function approve()
    {
        $db = Loader::db();
        $db->execute('UPDATE ConversationMessages SET cnvIsMessageApproved=1 WHERE cnvMessageID=?',array($this->cnvMessageID));
        $this->cnvIsMessageApproved = true;

        $cnv = $this->getConversationObject();
        if (is_object($cnv)) {
            $cnv->updateConversationSummary();
        }

    }
    public function unapprove()
    {
        $db = Loader::db();
        $db->execute('UPDATE ConversationMessages SET cnvIsMessageApproved=0 WHERE cnvMessageID=?',array($this->cnvMessageID));
        $this->cnvIsMessageApproved = false;

        $cnv = $this->getConversationObject();
        if (is_object($cnv)) {
            $cnv->updateConversationSummary();
        }
    }

    public function conversationMessageHasFlag($flag)
    {
        if (!$flag instanceof FlagType) {
            $flag = FlagType::getByHandle($flag);
        }
        if ($flag instanceof FlagType) {
            foreach ($this->getConversationMessageFlagTypes() as $type) {
                if ($flag->getConversationFlagTypeID() == $type->getConversationFlagTypeID()) {
                    return true;
                }
            }
        }

        return false;
    }
    public function getConversationMessageBodyOutput($dashboardOverride = false)
    {
        $editor = ConversationEditor::getActive();
        if ($dashboardOverride) {
            return $this->cnvMessageBody;
        } elseif ($this->cnvIsMessageDeleted) {
            return $editor->formatConversationMessageBody($this->getConversationObject(),t('This message has been deleted.'));
            //return t('This message has been deleted.');
        } elseif (!$this->cnvIsMessageApproved) {
            if ($this->conversationMessageHasFlag('spam')) {
                return $editor->formatConversationMessageBody($this->getConversationObject(),t('This message has been flagged as spam.'));
                //return t('This message has been flagged as spam.');
            }

            return $editor->formatConversationMessageBody($this->getConversationObject(),t('This message is queued for approval.'));
            // return t('This message is queued for approval.');
        } else {
            return $editor->formatConversationMessageBody($this->getConversationObject(),$this->cnvMessageBody);
        }
    }

    public function getConversationObject()
    {
        return Conversation::getByID($this->cnvID);
    }
    public function getConversationMessageUserObject()
    {
        return UserInfo::getByID($this->uID);
    }
    public function getConversationMessageUserID()
    {
        return $this->uID;
    }
    public function getConversationMessageDateTime()
    {
        return $this->cnvMessageDateCreated;
    }
    public function getConversationMessageDateTimeOutput($format = 'default')
    {
        $dh = Core::make('helper/date'); /* @var $dh \Concrete\Core\Localization\Service\Date */
        if (is_array($format)) { // custom date format

            return tc('Message posted date', 'Posted on %s', $dh->formatCustom($format[0], $this->cnvMessageDateCreated));
        }
        switch ($format) {
            case 'elapsed': // 3 seconds ago, 4 days ago, etc.
                $timestamp = strtotime($this->cnvMessageDateCreated);
                $time = array(
                    12 * 30 * 24 * 60 * 60 => 'Y',
                    30 * 24 * 60 * 60 => 'M',
                    24 * 60 * 60 => 'D',
                    60 * 60 => 'h',
                    60 => 'm',
                    1 => 's'
                );
                $ptime = time() - $timestamp;
                foreach ($time as $seconds => $unit) {
                    $elp = $ptime / $seconds;
                    if ($elp <= 0) {
                        return t2('%d second ago', '%d seconds ago', 0);
                    }
                    if ($elp >= 1) {
                        $rounded = round($elp);
                        switch ($unit) {
                            case 'Y':
                                return t2('%d year ago', '%d years ago', $rounded);
                            case 'M':
                                return t2('%d month ago', '%d months ago', $rounded);
                            case 'D':
                                return t2('%d day ago', '%d days ago', $rounded);
                            case 'h':
                                return t2('%d hour ago', '%d hours ago', $rounded);
                            case 'm':
                                return t2('%d minute ago', '%d minutes ago', $rounded);
                            case 's':
                                return t2('%d second ago', '%d seconds ago', $rounded);
                        }
                    }
                }
                break;
            case 'mdy':
                return tc('Message posted date', 'Posted on %s', $dh->formatDate($this->cnvMessageDateCreated));
            case 'mdy_full':
                return tc('Message posted date', 'Posted on %s', $dh->formatDate($this->cnvMessageDateCreated, true));
            case 'mdy_t':
                return tc('Message posted date', 'Posted on %s', $dh->formatDateTime($this->cnvMessageDateCreated));
            case 'mdy_full_t':
                return tc('Message posted date', 'Posted on %s', $dh->formatDateTime($this->cnvMessageDateCreated, true));
            case 'mdy_ts':
                return tc('Message posted date', 'Posted on %s', $dh->formatDateTime($this->cnvMessageDateCreated, false, true));
            case 'mdy_full_ts':
                return tc('Message posted date', 'Posted on %s', $dh->formatDateTime($this->cnvMessageDateCreated, true, true));
            default:
                return tc('Message posted date', 'Posted on %s', $dh->formatDate($this->cnvMessageDateCreated, true));
                break;
        }
    }
    public function rateMessage(Type $ratingType, $commentRatingIP, $commentRatingUserID, $post = array())
    {
        $db = Loader::db();
        $cnvRatingTypeID = $db->GetOne('SELECT * FROM ConversationRatingTypes WHERE cnvRatingTypeHandle = ?', array($ratingType->cnvRatingTypeHandle));
        $db->Execute('INSERT INTO ConversationMessageRatings (cnvMessageID, cnvRatingTypeID, cnvMessageRatingIP, timestamp, uID) VALUES (?, ?, ?, ?, ?)', array($this->getConversationMessageID(), $cnvRatingTypeID, $commentRatingIP, date('Y-m-d H:i:s'), $commentRatingUserID));
        $ratingType->adjustConversationMessageRatingTotalScore($this);
    }
    public function getConversationMessageRating(Type $ratingType)
    {
        $db = Loader::db();
        $cnt = $db->GetOne('SELECT count(*) from ConversationMessageRatings where cnvRatingTypeID = ? AND cnvMessageID = ?',  array($ratingType->getConversationRatingTypeID(), $this->cnvMessageID));

        return $cnt;
    }

    public function flag($flagtype)
    {
        if ($flagtype instanceof FlagType) {
            $db = Loader::db();
            foreach ($this->getConversationMessageFlagTypes() as $ft) {
                if ($ft->getConversationFlagTypeID() === $flagtype->getConversationFlagTypeID()) {
                    return;
                }
            }
            $db->execute('INSERT INTO ConversationFlaggedMessages (cnvMessageFlagTypeID, cnvMessageID) VALUES (?,?)',array($flagtype->getConversationFlagTypeID(),$this->getConversationMessageID()));
            $this->cnvMessageFlagTypes[] = $flagtype;
            $this->unapprove();

            return true;
        }
        throw new \Exception('Invalid flag type.');
    }

    public function unflag($flagtype)
    {
        if ($flagtype instanceof FlagType) {
            $db = Loader::db();
            $db->execute('DELETE FROM ConversationFlaggedMessages WHERE cnvMessageFlagTypeID = ? AND cnvMessageID = ?',array($flagtype->getConversationFlagTypeID(),$this->getConversationMessageID()));
            $this->cnvMessageFlagTypes[] = $flagtype;

            return true;
        }
        throw new \Exception('Invalid flag type.');
    }

    public static function getByID($cnvMessageID)
    {
        $db = Loader::db();
        $r = $db->GetRow('select * from ConversationMessages where cnvMessageID = ?', array($cnvMessageID));
        if (is_array($r) && $r['cnvMessageID'] == $cnvMessageID) {
            $cnv = new static();
            $cnv->getConversationMessageFlagTypes();
            $cnv->setPropertiesFromArray($r);

            return $cnv;
        }
    }

    public function attachFile(File $f)
    {
        $db = Loader::db();
        if (!is_object($f)) {
            return false;
        } else {
            $db->Execute('INSERT INTO ConversationMessageAttachments (cnvMessageID, fID) VALUES (?, ?)', array(
                $this->getConversationMessageID(),
                $f->getFileID()
            ));
        }
    }

    public function removeFile($cnvMessageAttachmentID)
    {
        $db = Loader::db();
        $db->Execute('DELETE FROM ConversationMessageAttachments WHERE cnvMessageAttachmentID = ?', array(
            $cnvMessageAttachmentID
        ));
    }

    public function getAttachments($cnvMessageID)
    {
        $db = Loader::db();
        $attachments = $db->Execute('SELECT * FROM ConversationMessageAttachments WHERE cnvMessageID = ?', array(
            $cnvMessageID
        ));

        return $attachments;
    }

    public function getAttachmentByID($cnvMessageAttachmentID)
    {
        $db = Loader::db();
        $attachment = $db->Execute('SELECT * FROM ConversationMessageAttachments WHERE cnvMessageAttachmentID = ?', array(
        $cnvMessageAttachmentID
        ));

        return $attachment;
    }

    public static function add($cnv, $cnvMessageSubject, $cnvMessageBody, $parentMessage = false, $user = false)
    {
        $db = Loader::db();
        $date = Loader::helper('date')->getSystemDateTime();
        $uID = 0;

        if (is_object($user)) {
            $ux = $user;
        } else {
            $ux = new User();
        }

        if ($ux->isRegistered()) {
            $uID = $ux->getUserID();
        }
        $cnvMessageParentID = 0;
        $cnvMessageLevel = 0;
        if (is_object($parentMessage)) {
            $cnvMessageParentID = $parentMessage->getConversationMessageID();
            $cnvMessageLevel = $parentMessage->getConversationMessageLevel() + 1;
        }

        $cnvID = 0;
        if ($cnv instanceof Conversation) {
            $cnvID = $cnv->getConversationID();
        }

        $editor = ConversationEditor::getActive();
        $cnvEditorID = $editor->getConversationEditorID();

        $r = $db->Execute('insert into ConversationMessages (cnvMessageSubject, cnvMessageBody, cnvMessageDateCreated, cnvMessageParentID, cnvEditorID, cnvMessageLevel, cnvID, uID, cnvMessageSubmitIP, cnvMessageSubmitUserAgent) values (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                          array($cnvMessageSubject, $cnvMessageBody, $date, $cnvMessageParentID, $cnvEditorID, $cnvMessageLevel, $cnvID, $uID, ip2long(Loader::Helper('validation/ip')->getRequestIP()), $_SERVER['HTTP_USER_AGENT']));

        $cnvMessageID = $db->Insert_ID();

        if ($cnv instanceof Conversation) {
            $cnv->updateConversationSummary();
        }

        return static::getByID($cnvMessageID);
    }

    public function delete()
    {
        $db = Loader::db();
        $db->Execute('update ConversationMessages set cnvIsMessageDeleted = 1, cnvIsMessageApproved = 0 where cnvMessageID = ?', array(
            $this->cnvMessageID
        ));

        $cnv = $this->getConversationObject();
        if (is_object($cnv)) {
            $cnv->updateConversationSummary();
        }

        $this->cnvIsMessageDeleted = true;
        //$this->cnvMessageSubject = null;
        //$this->cnvMessageBody = null;
        // $this->uID = USER_DELETED_CONVERSATION_ID;
    }

    public function restore()
    {
        $db = Loader::db();
        $db->Execute('update ConversationMessages set cnvIsMessageDeleted = 0 where cnvMessageID = ?', array(
            $this->cnvMessageID
        ));

        $cnv = $this->getConversationObject();
        if (is_object($cnv)) {
            $cnv->updateConversationSummary();
        }

        $this->cnvIsMessageDeleted = false;
        //$this->cnvMessageSubject = null;
        //$this->cnvMessageBody = null;
        // $this->uID = USER_DELETED_CONVERSATION_ID;
    }

}
