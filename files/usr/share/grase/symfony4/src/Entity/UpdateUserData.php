<?php

namespace App\Entity;

use App\Entity\Radius\Check;
use App\Entity\Radius\Group;
use App\Entity\Radius\Reply;
use App\Entity\Radius\User;
use App\Entity\Radius\UserGroup;
use Doctrine\Common\Persistence\ObjectManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * This class is used for updating Radius users. There is lots of complicated connections which can't be handled at the
 * entity level, so this class does all the hard yards
 */
class UpdateUserData
{
    /**
     * @var string
     *
     * @Assert\NotBlank()
     */
    public $username;

    /**
     * @var string
     */
    public $comment;

    /**
     * @var string
     */
    public $password;

    /** @var Group */
    public $primaryGroup;

    /**
     * @var array
     */
    public $dataLimit = ['dataLimitDropdown' => 'inherit', 'dataLimitCustom' => null];

    /**
     * @var array
     */
    public $timeLimit = ['timeLimitDropdown' => 'inherit', 'timeLimitCustom' => null];

    /**
     * @var \DateTime|null
     */
    public $expiry;

    /**
     * @var string|null
     */
    public $lockMessage;

    /**
     * Create a UpdateUserData from an existing User entity
     *
     * @param User $user
     *
     * @return UpdateUserData
     */
    public static function fromUser(User $user): self
    {
        $updateUserData = new self();
        $updateUserData->username = $user->getUsername();
        //$updateUserData->password = $user->getPassword();
        $updateUserData->comment = $user->getComment();
        $updateUserData->primaryGroup = $user->getPrimaryGroup();
        $updateUserData->expiry = $user->getExpiry();
        $updateUserData->lockMessage = $user->getReplyMessage();
        // Existing users don't use the dropdowns
        $updateUserData->dataLimit['dataLimitDropdown'] = null;
        $updateUserData->timeLimit['timeLimitDropdown'] = null;
        if ($user->getDataLimit() !== null) {
            $updateUserData->dataLimit['dataLimitCustom'] = $user->getDataLimitMebibyte();
        }
        if ($user->getTimeLimitSeconds() !== null) {
            $updateUserData->timeLimit['timeLimitCustom'] = $user->getTimeLimitMinutes();
        }

        return $updateUserData;
    }

    /**
     * Helper function for deleting a user account
     *
     * Removes user groups and radius checks. Doesn't touch accounting data
     *
     * @param User                   $user
     * @param EntityManagerInterface $entityManager
     *
     * @return bool
     */
    public static function deleteUser(User $user, EntityManagerInterface $entityManager)
    {
        // Remove radius checks
        foreach ($user->getRadiuscheck() as $check) {
            $entityManager->remove($check);
        }

        // Remove user groups
        foreach ($user->getUserGroups() as $group) {
            $entityManager->remove($group);
        }

        // Remove radius reply
        foreach ($user->getRadiusReply() as $reply) {
            $entityManager->remove($reply);
        }

        // @TODO remove from batches

        $entityManager->flush();

        $entityManager->remove($user);
        $entityManager->flush();

        return true;
    }

    /**
     * @Assert\Callback
     *
     * @param ExecutionContextInterface $context
     */
    public function validateFields(ExecutionContextInterface $context)
    {
        if (null !== $this->dataLimit['dataLimitDropdown'] && null !== $this->dataLimit['dataLimitCustom']) {
            $context->addViolation('grase.form.user.datalimit.both_selected_error');
        }
        if (null !== $this->timeLimit['timeLimitDropdown'] && null !== $this->timeLimit['timeLimitCustom']) {
            $context->addViolation('grase.form.user.timelimit.both_selected_error');
        }
    }

    /**
     * Write data back to a User entity with the updated data
     *
     * @param User          $user
     * @param ObjectManager $em
     * @param bool          $newUser     if this is the first time a user is being created
     * @param bool          $resetExpiry if we should force reset the expiry of the user
     */
    public function updateUser(User $user, ObjectManager $em, $newUser = false, $resetExpiry = false)
    {
        $user->setComment($this->comment);
        $groupChanged = $this->setPrimaryGroup($user, $em, $this->primaryGroup);

        $this->setDataLimit($user, $em, $this->dataLimitToBytes());
        $this->setTimeLimit($user, $em, $this->timeLimitToSeconds());

        /* Only update the password if we have a value. We don't load a value by default, so it
         * should only be present if we are changing it
         *
         * We also then don't let you easily delete a password once set, probably a good thing
         */
        if ($this->password !== null) {
            $this->setPassword($user, $em, $this->password);
        }
        // @TODO update the rest

        // Reset the expiry for new users, if we're forcing a reset, or if the group has changed
        if ($newUser || $resetExpiry || $groupChanged) {
            $this->resetExpiry($user, $em);
        }

        // Update the expireAfter if present and expiry isn't set
        if (!$this->expiry) {
            $this->setExpireAfter($user, $em);
        }

        // Update Account Lock status
        $this->setLock($user, $em, $this->lockMessage);

        $em->persist($user);
        $em->flush();
    }

    /**
     * @return float|int|mixed|null
     */
    private function dataLimitToBytes()
    {
        if ($this->dataLimit['dataLimitDropdown'] === 'inherit') {
            return null;
        }
        if ($this->dataLimit['dataLimitDropdown'] !== null) {
            return $this->dataLimit['dataLimitDropdown'];
        }

        // Custom Data Limit is in MiBs and we want Bytes
        if (null !== $this->dataLimit['dataLimitCustom']) {
            return (int) $this->dataLimit['dataLimitCustom'] * 1024 * 1024;
        }

        return null;
    }

    /**
     * Reset the users Expiry based on the group Expiry
     *
     * @param User          $user
     * @param ObjectManager $em
     */
    private function resetExpiry(User $user, ObjectManager $em)
    {
        $expiry = $this->primaryGroup->getExpiry();
        $expiryCheck = $user->getExpiryCheck();
        if (empty($expiry) && $expiryCheck) {
            // We just need to remove the check
            $em->remove($expiryCheck);

            return;
        }

        if (empty($expiry)) {
            // We don't have a check already, so nothing to do;
            return;
        }

        if (!$expiryCheck) {
            // We need to create a check
            $expiryCheck = new Check();
            $expiryCheck->setAttribute('Expiration');
            $expiryCheck->setUser($user);
            $expiryCheck->setOp(':=');
        }

        // Just set the check we have
        $expiryCheck->setValue(date('F d Y H:i:s', strtotime($expiry)));
        $em->persist($expiryCheck);
    }

    /**
     * Set the users Expire After based on the group Expire After setting
     *
     * @param User          $user
     * @param ObjectManager $em
     */
    private function setExpireAfter(User $user, ObjectManager $em)
    {
        $expireAfter = $this->primaryGroup->getExpireAfter();
        $expireAfterCheck = $user->getExpireAfterCheck();
        if (empty($expireAfter) && $expireAfterCheck) {
            // We just need to remove the check
            $em->remove($expireAfterCheck);

            return;
        }

        if (empty($expireAfter)) {
            // We don't have a check already, so nothing to do;
            return;
        }

        if (!$expireAfterCheck) {
            // We need to create a check
            $expireAfterCheck = new Check();
            $expireAfterCheck->setAttribute(Check::GRASE_EXPIRE_AFTER);
            $expireAfterCheck->setUser($user);
            $expireAfterCheck->setOp(':=');
        }

        // Just set the check we have
        $expireAfterCheck->setValue($expireAfter);
        $em->persist($expireAfterCheck);
    }

    /**
     * @param User          $user
     * @param ObjectManager $em
     * @param int|null      $bytes
     */
    private function setDataLimit(User $user, ObjectManager $em, $bytes)
    {
        $dataLimitCheck = $user->getDataLimitCheck();
        if (null === $bytes && $dataLimitCheck) {
            // We just need to remove the check
            $em->remove($dataLimitCheck);

            return;
        }
        if (null === $bytes) {
            // We don't have a check already, so nothing to do;
            return;
        }
        if (!$dataLimitCheck) {
            // We need to create a check
            $dataLimitCheck = new Check();
            $dataLimitCheck->setAttribute('Max-Octets');
            $dataLimitCheck->setUser($user);
            $dataLimitCheck->setOp(':=');
        }
        // Just set the check we have
        $dataLimitCheck->setValue((string) $bytes);
        $em->persist($dataLimitCheck);
    }

    /**
     * @return float|int|mixed|null
     */
    private function timeLimitToSeconds()
    {
        if ($this->timeLimit['timeLimitDropdown'] === 'inherit') {
            return null;
        }
        if ($this->timeLimit['timeLimitDropdown'] !== null) {
            return $this->timeLimit['timeLimitDropdown'];
        }

        // Custom Data Limit is in Minutes and we want Seconds
        if ($this->timeLimit['timeLimitCustom'] !== null) {
            return (int) $this->timeLimit['timeLimitCustom'] * 60;
        }

        return null;
    }

    /**
     * @param User          $user
     * @param ObjectManager $em
     * @param int|null      $seconds
     */
    private function setTimeLimit(User $user, ObjectManager $em, $seconds)
    {
        $timeLimitCheck = $user->getTimeLimitCheck();
        if (null === $seconds && $timeLimitCheck) {
            // We just need to remove the check
            $em->remove($timeLimitCheck);

            return;
        }
        if (null === $seconds) {
            // We don't have a check already, so nothing to do;
            return;
        }
        if (!$timeLimitCheck) {
            // We need to create a check
            $timeLimitCheck = new Check();
            $timeLimitCheck->setAttribute('Max-All-Session');
            $timeLimitCheck->setUser($user);
            $timeLimitCheck->setOp(':=');
        }
        // Just set the check we have
        $timeLimitCheck->setValue((string) $seconds);
        $em->persist($timeLimitCheck);
    }

    /**
     * @param User          $user
     * @param ObjectManager $em
     * @param Group         $group
     *
     * @return bool
     */
    private function setPrimaryGroup(User $user, ObjectManager $em, Group $group)
    {
        /** @var UserGroup $primaryUserGroup */
        $primaryUserGroup = $user->getPrimaryUserGroup();
        if (!$primaryUserGroup) {
            $primaryUserGroup = new UserGroup();
            $primaryUserGroup->setUser($user);
        }
        $oldGroup = $primaryUserGroup->getGroup();
        $primaryUserGroup->setGroup($group);

        $em->persist($primaryUserGroup);

        return $oldGroup !== $group;
    }

    /**
     * Updates a user Cleartext-Password check attribute to set their CHAP password
     *
     * @param User          $user
     * @param ObjectManager $em
     * @param               $password
     */
    private function setPassword(User $user, ObjectManager $em, $password)
    {
        $passwordCheck = $user->getPasswordCheck();
        if (!$passwordCheck) {
            // We need to create a check
            $passwordCheck = new Check();
            $passwordCheck->setAttribute('Cleartext-Password');
            $passwordCheck->setUser($user);
            $passwordCheck->setOp(':=');
        }

        // Just set the check we have
        $passwordCheck->setValue($password);
        $em->persist($passwordCheck);
    }

    /**
     * @param User          $user
     * @param ObjectManager $em
     * @param string|null   $message
     */
    private function setLock(User $user, ObjectManager $em, $message = null)
    {
        $replyMessageReply = $user->getReplyMessageReply();
        $lockCheck = $user->getLockCheck();

        if (trim($message) === '') {
            $message = null;
        }

        /* Handle removal of checks/replies if message is null (clearing the lock) */
        if (null === $message && $lockCheck) {
            // We just need to remove the check
            $em->remove($lockCheck);
        }
        if (null === $message && $replyMessageReply) {
            // We just need to remove the reply
            $em->remove($replyMessageReply);
        }
        if (null === $message) {
            // We have removed any $lockCheck or $replyMessageReply already, so we can now return
            return;
        }

        /* Handle the creation of the checks/replies, we need to lock the user and/or update the reply */
        if (!$lockCheck) {
            // We need to create a check
            $lockCheck = new Check();
            $lockCheck->setAttribute(Check::AUTH_TYPE);
            $lockCheck->setUser($user);
            $lockCheck->setOp(':=');
        }
        // Now set the check we have
        $lockCheck->setValue('reject');

        if (!$replyMessageReply) {
            // We need to create a reply
            $replyMessageReply = new Reply();
            $replyMessageReply->setAttribute(Reply::REPLY_MESSAGE);
            $replyMessageReply->setUser($user);
            $replyMessageReply->setOp(':=');
        }
        // Now set the reply we have
        $replyMessageReply->setValue($message);

        $em->persist($lockCheck);
        $em->persist($replyMessageReply);
    }
}
