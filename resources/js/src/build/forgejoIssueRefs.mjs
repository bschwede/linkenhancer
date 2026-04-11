/**
 * after migrating a repo to codeberg in issues automatic references to commits and pull requests are missing
 */
import * as childProcess from "node:child_process"
import log from 'fancy-log'

const MARKER = "<!-- forgejo-migration:issue-ref-summary -->";

export default class ForgejoIssueRefsCli {
    constructor(config, options = {}) {
        this.config = config;
        this.options = {
            dryRun: Boolean(options.dryRun),
            gitPath: options.gitPath || "git",
            repoPath: options.repoPath || ".",
            since: options.since || "2020-01-01",
            issueNumbers: parseIssueRange(options.issueNumbers),
        };
    }

    async api(pathname, options = {}) {
        const url = `${this.config.forgejoUrl}/api/v1${pathname}`;
        const resp = await fetch(url, {
            ...options,
            headers: {
                Authorization: `token ${this.config.token}`,
                Accept: "application/json",
                "Content-Type": "application/json",
                ...options.headers,
            },
        });
        if (!resp.ok) {
            const text = await resp.text().catch(() => "(no body)");
            throw new Error(`Forgejo API failed: ${resp.status} ${resp.statusText}\nURL: ${url}\n${text}`);
        }
        return resp.json();
    }

    parseRefs(text) {
        return [...new Set([...text.matchAll(/#(\d+)/g)].map((m) => Number(m[1])).filter(Number.isFinite))];
    }

    matchesIssueFilter(issueNums) {
        const filter = this.options.issueNumbers;
        return filter.length === 0 || issueNums.some((n) => filter.includes(n));
    }

    async getGitCommits() {
        const { gitPath, repoPath, since } = this.options;
        const out = childProcess.execFileSync(
            gitPath,
            ["log", "--all", `--since=${since}`, "--pretty=format:%H%x1f%s%x1f%b%x1e"],
            { cwd: repoPath, encoding: "utf8", maxBuffer: 20 * 1024 * 1024 }
        );

        return out
            .split("\x1e")
            .map((s) => s.trim())
            .filter(Boolean)
            .map((entry) => {
                const [sha, subject, body = ""] = entry.split("\x1f");
                const msg = `${subject}\n${body}`.trim();
                const issueNums = this.parseRefs(msg);
                return issueNums.length && this.matchesIssueFilter(issueNums)
                    ? { type: "commit", sha, subject: subject.trim(), body: body.trim(), issueNums }
                    : null;
            })
            .filter(Boolean);
    }

    async getPullRequests() {
        const { owner, repo } = this.config;
        const prs = await this.api(`/repos/${owner}/${repo}/pulls?state=all&limit=100`);

        return prs
            .map((pr) => {
                const text = `${pr.title}\n${pr.body || ""}`;
                const issueNums = this.parseRefs(text);
                return issueNums.length && this.matchesIssueFilter(issueNums)
                    ? {
                        type: "pull_request",
                        prIndex: pr.number,
                        title: pr.title,
                        body: pr.body || "",
                        state: pr.state,
                        url: pr.html_url,
                        issueNums,
                    }
                    : null;
            })
            .filter(Boolean);
    }

    groupByIssue(items) {
        const map = new Map();
        for (const item of items) {
            for (const issueNum of item.issueNums) {
                if (this.options.issueNumbers.length && !this.options.issueNumbers.includes(issueNum)) continue;
                if (!map.has(issueNum)) map.set(issueNum, []);
                map.get(issueNum).push(item);
            }
        }
        return map;
    }

    formatComment(issueNum, items) {
        const { forgejoUrl, owner, repo } = this.config;
        const lines = [
            MARKER,
            `Automatically generated references for the issue #${issueNum}.`,
            "",
        ];

        for (const item of items) {
            if (item.type === "commit") {
                const shortSha = item.sha.slice(0, 12);
                lines.push(`- commit [\`${shortSha}\`](${forgejoUrl}/${owner}/${repo}/commit/${item.sha}) — ${item.subject}`);
            } else if (item.type === "pull_request") {
                lines.push(`- PR [#${item.prIndex}](${forgejoUrl}/${owner}/${repo}/pulls/${item.prIndex}) — ${item.title}`);
            }
        }

        return lines.join("\n");
    }

    async listIssueComments(issueIndex) {
        const { owner, repo } = this.config;
        return this.api(`/repos/${owner}/${repo}/issues/${issueIndex}/comments?limit=100`);
    }

    async createOrUpdateIssueComment(issueIndex, body, dryRun) {
        const { owner, repo } = this.config;
        const existing = await this.listIssueComments(issueIndex);
        const markerComment = existing.find((c) => (c.body || "").includes(MARKER));

        if (dryRun) {
            log(`[DRY-RUN] ${markerComment ? "UPDATE" : "CREATE"} comment for #${issueIndex}`);
            log(body);
            return;
        }
        log(`issue #${issueIndex}`)

        const path = markerComment
            ? `/repos/${owner}/${repo}/issues/comments/${markerComment.id}`
            : `/repos/${owner}/${repo}/issues/${issueIndex}/comments`;

        const method = markerComment ? "PATCH" : "POST";

        await this.api(path, {
            method,
            body: JSON.stringify({ body }),
        });
    }

    async run({ dryRun = this.options.dryRun } = {}) {
        const commits = await this.getGitCommits();
        const prs = await this.getPullRequests();
        const grouped = this.groupByIssue([...commits, ...prs]);

        log(`found - commits = ${commits.length}, PRs = ${prs.length}`)

        for (const issueNum of [...grouped.keys()].sort((a, b) => a - b)) {
            const body = this.formatComment(issueNum, grouped.get(issueNum));
            await this.createOrUpdateIssueComment(issueNum, body, dryRun);
        }
    }
}

/**
 * Parse Issue-Number-String like "12-20,45,99" into array of numbers.
 * @param {string | null | undefined} str - e.g. "12-20,45,99" or "12,45,99"
 * @returns {number[]} sorted, unique numbers
 */
function parseIssueRange(str) {
    if (!str || typeof str !== "string") return [];

    const result = new Set();

    str.split(",")
        .map((piece) => piece.trim())
        .filter((piece) => piece.length > 0)
        .forEach((piece) => {
            const match = piece.match(/^(\d+)-(\d+)$/);
            if (match) {
                const start = parseInt(match[1], 10);
                const end = parseInt(match[2], 10);
                if (start <= end) {
                    for (let i = start; i <= end; i++) {
                        result.add(i);
                    }
                }
            } else {
                const num = parseInt(piece, 10);
                if (!Number.isNaN(num) && num >= 1) {
                    result.add(num);
                }
            }
        });

    return Array.from(result).sort((a, b) => a - b);
}