---
paths:
  - 'app/Http/Controllers/Project*.php'
  - 'app/Http/Requests/Projects/**'
---

# Projects

## Project management needs TeamPermission::ManageProjects
Owners and Admins hold `project:manage` (`TeamPolicy::manageProjects`). It gates renaming and deleting a project, revoking keys, browser origins, and repository connect/update/disconnect/list. Where a FormRequest is used, the check lives in its `authorize()` so a Member gets 403 before validation; elsewhere it is `Gate::authorize` in the controller. Members may still create projects and keys, so onboarding works for them. `projects.show` passes `canManage`, and Show.vue hides those controls.
