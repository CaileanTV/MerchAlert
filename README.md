# CCMerch Alert System

The **CCMerch Alert System** is a merchandise-driven alert and engagement platform designed for creators.

It allows creators to display real-time purchase alerts, customize their overlays, and automatically engage their communities when merchandise is purchased.

## 🤖 Chatbot Integration

The CCMerch Alert System includes an integrated Twitch Chatbot that can automatically send messages to a creator's Twitch chat when merchandise is purchased.

Creators can configure their own message templates and enable or disable the Chatbot directly from the Creator Portal.

---

## 💬 Automatic Twitch Chat Messages

The Chatbot can automatically send customizable messages when merchandise is purchased.

Messages are processed through the CCMerch Alert System and delivered to the Creator's configured Twitch channel.

---

## 🎮 Twitch Helix Chat API

The CCMerch Alert System uses Twitch's official **Helix Chat API** for Chatbot message delivery.

The system does not rely on IRC for production message delivery, providing a modern and streamlined integration with Twitch's chat platform.

---

## 🔐 Persistent Authentication

The Chatbot automatically manages the Twitch bot account's authentication lifecycle.

This includes:

* Background token validation
* Automatic token refresh
* Persistent authentication management
* Reduced need for manual reconnections

The system is designed to keep the Chatbot operational without requiring frequent administrator interaction.

---

## ⚙️ Simplified Creator Setup

Creators do not need to complete a separate Twitch channel authorization flow for standard message delivery.

The CCMerch administrator manages the central Twitch bot account and assigns Twitch channels to individual creators.

Creators can configure their Chatbot settings directly from the Creator Portal.

---

## 🛡️ Chat Restrictions

Twitch chat restrictions may affect whether the Chatbot can send messages in certain situations.

Creators who want the Chatbot to bypass applicable chat restrictions can optionally grant the bot additional privileges in their Twitch channel.

For example:

```text
/mod botname
```

or:

```text
/vip botname
```

Depending on the channel configuration, granting the bot Moderator or VIP privileges may help ensure reliable message delivery.

---

## 📊 Runtime Diagnostics

The Admin Dashboard includes Chatbot runtime diagnostics and delivery information.

Administrators can monitor:

* Bot authentication status
* Token refresh activity
* Message delivery statistics
* Failed message attempts
* Channel configuration
* Chatbot runtime health

---

## 🏗️ How It Works

```text
Merchandise Purchase
        ↓
CCMerch Alert System
        ↓
Alert & Event Processing
        ↓
Chatbot Message Service
        ↓
Twitch Helix Chat API
        ↓
Creator's Twitch Chat
```

The CCMerch Alert System provides the central event and alert infrastructure, while individual features such as overlays, alerts, alert history, and the Twitch Chatbot build upon the same platform.

---
