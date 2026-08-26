# Git: branches, switching, and pushing

A practical reference for working on this project. See [SETUP.md](SETUP.md) for
getting the app running on a machine.

## The mental model

A branch is just a **movable label pointing at a commit**. Creating one is
instant and costs nothing — it does not copy your files. When you commit, the
label you are standing on moves forward with you.

That is the whole idea. Most git confusion dissolves once branches stop feeling
like folders.

## The daily loop

Ninety percent of your git use is these four commands:

```bash
git status          # where am I, what has changed
git add -A          # stage everything
git commit -m "..." # save a snapshot
git push            # send it to GitHub
```

Run `git status` constantly. It is free and always tells you the truth.

## Creating a branch

Always branch from an up-to-date `main`:

```bash
git switch main
git pull
git switch -c feature/whatever-you-are-building
```

`-c` means create. You are now on the new branch, and `main` stays where it was.

Work, then save and publish it:

```bash
git add -A
git commit -m "Describe what changed"
git push -u origin feature/whatever-you-are-building
```

`-u` links your local branch to GitHub. **It is only needed on the first push** —
after that, plain `git push` works.

### Naming

Anything descriptive is fine. This project uses `feature/<what-it-does>`:

```
feature/onedrive-repository-sync
feature/admin-profile-dropdown
```

## Switching branches

```bash
git switch main                              # an existing local branch
git switch feature/onedrive-repository-sync
```

For a branch that exists on GitHub but not yet on this machine:

```bash
git fetch origin
git switch feature/some-branch
```

Git finds it on the remote and links it up automatically.

To see what you have:

```bash
git branch -vv    # local branches, and what each tracks
git branch -a     # local and remote
```

> `git checkout` does the same job. It is the older command and also does five
> unrelated things, which is why `switch` exists. Tutorials will show you
> `checkout` — they are equivalent for this purpose.

### The one rule: commit before you switch

Git refuses to switch if uncommitted changes would be overwritten:

```
error: Your local changes to the following files would be overwritten by checkout
```

That is git protecting your work, not a bug. Commit first, then switch.

## Pushing

```bash
git push                     # branch is already linked
git push -u origin <branch>  # first push of a new branch
```

Check what you are about to send before you send it:

```bash
git status                            # says "ahead 1", "ahead 3", etc.
git log --oneline origin/main..HEAD   # the actual commits
```

## Merging into main

A pull request is a **request to pull your branch's commits into another
branch**. It is a GitHub feature, not a git one — git only knows branches and
merges.

Opening a PR changes nothing. No code moves until someone clicks Merge. What it
buys you is a reviewable diff, an automatic conflict check, and a record of why
the change happened that commit messages alone rarely capture.

**On GitHub** (preferred):

```
https://github.com/AdrianBalmes/qualityassurancesystem---php-system/compare/main...YOUR-BRANCH
```

Create pull request → read the **Files changed** tab → Merge pull request.

Then update your local copy:

```bash
git switch main
git pull
```

**Locally**, if you would rather skip the review step:

```bash
git switch main
git pull
git merge feature/your-branch
git push
```

## Recipe: a commit landed on the wrong branch

You committed to `main` when it should have gone on a branch, and you have not
pushed yet. Three commands:

```bash
git branch feature/new-branch-name   # plant a label on the current commit
git reset --hard origin/main         # move main back to GitHub's version
git switch feature/new-branch-name   # go to the new branch
```

Why this is safe: step 1 labels the commit **before** step 2 moves `main` off
it, so it is never orphaned. Confirm with `git log --oneline -3`.

`git reset --hard` does throw away uncommitted changes, so run `git status`
first and make sure it is clean.

## Keeping a branch fresh

Do not let a branch sit for weeks. `feature/audit-rec-file-upload` in this repo
fell three weeks behind `main` and now conflicts in five files, one of which
`main` had deleted outright.

Merge `main` into your branch regularly:

```bash
git switch feature/your-branch
git merge main
```

Conflicts caught early are small. Conflicts caught after a month are a project.

## When something looks wrong

| Message                                     | Meaning                            | Fix                             |
| ------------------------------------------- | ---------------------------------- | ------------------------------- |
| `Your local changes would be overwritten` | uncommitted work blocks the switch | commit first                    |
| `Updates were rejected`                   | GitHub has commits you do not      | `git pull`, then push         |
| `no upstream branch`                      | first push of a new branch         | `git push -u origin <branch>` |
| `detached HEAD`                           | you are on a commit, not a branch  | `git switch main`             |
| `Already up to date` on merge             | nothing to bring in                | nothing wrong                   |

### The safety net

```bash
git reflog
```

Lists everywhere `HEAD` has been for the last 90 days, including commits no
branch points at any more. Almost nothing in git is truly lost. If you panic,
run `git reflog` before anything drastic.

## Things that do not travel through git

| Not in git         | How to get it on the other machine                |
| ------------------ | ------------------------------------------------- |
| `.env`           | `cp .env.example .env`, then fill in            |
| Your database rows | `mysqldump` / import — see [SETUP.md](SETUP.md) |

`.env` is gitignored on purpose. Never commit real credentials.
