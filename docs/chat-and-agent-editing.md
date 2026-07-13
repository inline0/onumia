---
title: "Chat And Agent Editing"
meta_title: "Onumia Chat And Agent Editing"
meta_description: "Use the Onumia AI chat sidebar to edit custom modules and apps: providers and keys, validated agent file edits, shared chats, and locks."
path: "chat-and-agent-editing"
order: 400
section: "Customization"
---

# Chat And Agent Editing

Custom modules and custom apps carry an AI chat sidebar. You describe a change in plain language, and the assistant edits the entity's files for you: restructure a settings screen, add a setting with its validation, adjust messages, or extend the PHP behavior. Every edit the agent makes passes through the same Onumia validation as a manual edit, and nothing touches disk until you save.

Chat is available only for custom entities, because those are the only entities whose files belong to you. Bundled modules show no chat sidebar.

## Providers and keys

Onumia does not bundle or resell model access. You bring your own API keys for the supported providers, OpenAI, Anthropic, and Google, and the dashboard calls the provider directly from your browser. WordPress stores the conversation history, but prompts and completions stream between your browser and the provider; there is no generation proxy on your server.

Keys are supplied by the site operator in a `.env` file in the Onumia plugin directory, using the provider's standard variable names such as `OPENAI_API_KEY`, `ANTHROPIC_API_KEY`, and `GOOGLE_GENERATIVE_AI_API_KEY`. Onumia passes them to the dashboard for users who can access it, which is limited to administrators. Configure only the providers you use; the model selector offers models for the keys that are present.

The model selector lists tool-capable models for each provider, since agent editing depends on tool use. For models that support it, a reasoning control lets you raise, lower, or disable provider reasoning per conversation.

## What the agent can do

When you send a message, the agent works inside an in-memory copy of the entity's files, the manifest, the screen definition, the behavior file, and the messages, using read, write, and shell-style tools that are confined to those files. It cannot reach your filesystem, other modules, or anything outside the entity it is editing, and it works within a bounded number of steps per request.

The agent is briefed automatically. Onumia supplies a system context describing the module file contract, the rules for the PHP behavior file, and the JSON schemas for every editable file, so the assistant knows the product's conventions without you explaining them.

## Validation and rollback

Every write-like tool call is checked before it is accepted. Onumia runs the changed files through the same checker that guards manual edits: schema validation for the JSON files, non-executing parsing of the PHP contract, and cross-validation of every reference between the screen and the behavior file. If the check fails, the edit is rolled back to the last accepted state and the diagnostics are returned to the agent, which typically corrects the problem and tries again within the same conversation turn.

Accepted edits update the working draft: changes to the screen definition render immediately in the settings screen beside the chat, so you can watch the result take shape. The draft becomes real only when you save, which runs the full backend check once more and records a history version. If a session produced something you do not want, discard the draft or revert the save through history.

## Conversations are persisted and shareable

Chats are stored in WordPress per custom entity, so each module or app keeps its own conversation list and you can return to a thread later from any browser. Conversations are private to their owner by default. The owner, or any member with admin permission on the chat, can add other users as members with read, write, or admin permission, which turns a thread into a shared working session: write members can contribute prompts, read members can follow along, and open chats refresh continuously so everyone sees new messages as they arrive.

While a response is streaming, the chat takes a short-lived editing lock so two people cannot generate into the same thread at once. Others see who is currently working in the chat, and the lock expires on its own within a couple of minutes if a browser disappears mid-generation.

## Cost and privacy considerations

Because calls go directly from the browser to the provider on your keys, usage is billed to your provider accounts, and the files of the entity being edited, plus your prompts, are sent to the provider you select. Conversation history, including tool activity, is stored in your WordPress database. Treat both with the same care as any operational tooling that handles your site's code: share keys only through the plugin `.env` file, and share chats deliberately.
