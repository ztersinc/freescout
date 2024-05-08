<?php

namespace App\Policies;

use App\Conversation;
use App\Mailbox;
use App\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class ConversationPolicy
{
    use HandlesAuthorization;

    /**
     * Determine whether the user can view the conversation.
     *
     * @param \App\User         $user
     * @param \App\Conversation $conversation
     *
     * @return bool
     */
    public function view(User $user, Conversation $conversation)
    {
        if ($user->isAdmin()) {
            return true;
        } else {
            if ($conversation->mailbox->users->contains($user)) {
                // Maybe user can see only assigned conversations.
                if (!\Eventy::filter('conversation.is_user_assignee', $conversation->user_id == $user->id, $conversation, $user->id)
                    && $conversation->created_by_user_id != $user->id
                    && $user->canSeeOnlyAssignedConversations()
                ) {
                    return false;
                } else {
                    return true;
                }
            } else {
                return false;
            }
        }
    }

    /**
     * Cached version.
     * 
     * @param  User         $user         [description]
     * @param  Conversation $conversation [description]
     * @return [type]                     [description]
     */
    public function viewCached(User $user, Conversation $conversation)
    {
        if ($user->isAdmin()) {
            return true;
        } else {
            if ($conversation->mailbox->users_cached->contains($user)) {
                // Maybe user can see only assigned conversations.
                if (!\Eventy::filter('conversation.is_user_assignee', $conversation->user_id == $user->id, $conversation, $user->id)
                    && $conversation->created_by_user_id != $user->id
                    && $user->canSeeOnlyAssignedConversations()
                ) {
                    return false;
                } else {
                    return true;
                }
            } else {
                return false;
            }
        }
    }

    /**
     * Determine whether the user can update the conversation.
     *
     * @param \App\User         $user
     * @param \App\Conversation $conversation
     *
     * @return bool
     */
    public function update(User $user, Conversation $conversation)
    {
        if ($user->isAdmin()) {
            return true;
        } else {
            if ($conversation->mailbox->users->contains($user)) {
                return true;
            } else {
                return false;
            }
        }
    }

    /**
     * Check if user can delete conversation.
     */
    public function delete(User $user, Conversation $conversation)
    {
        if ($user->isAdmin()) {
            return true;
        } else {
            return $user->hasPermission(User::PERM_DELETE_CONVERSATIONS);
        }
    }

    /**
     * Determine whether current user can move conversations
     *
     * @param \App\User    $user
     * @param \App\Mailbox $mailbox
     *
     * @return mixed
     */
    public function move(User $user)
    {
        // First check this, because it is cached in conversation page
        if (count($user->mailboxesCanView(true)) > 1) {
            return true;
        }
        return Mailbox::count() > 1;
    }
}
