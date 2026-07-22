=== Woo Agentic Checkout ===
Contributors: vimes1984
Tags: woocommerce, checkout, ai, llm, a/b testing, conversion optimization, self-healing
Requires at least: 6.0
Tested up to: 6.6
Requires PHP: 8.0
Stable tag: 0.1.0-alpha
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

LLM-powered agentic checkout optimisation. Self-healing, auto A/B testing, and signal-driven improvements via GA4 & sales data.

== Description ==

🍌 **Woo Agentic Checkout** is the first agentic WooCommerce plugin — it doesn't just track checkout performance, it *improves it*.

Five autonomous AI agents work around the clock:

* 🔍 **Conversion Analyzer** — Tracks conversion rate, funnel drop-offs, and revenue trends
* 🧪 **AB Optimizer** — Creates and analyses checkout A/B experiments using Bayesian statistics
* 🚨 **Error Detector** — Monitors checkout errors, classifies severity, and triggers healing
* 💡 **Suggestion Generator** — Uses LLM to produce ranked checkout improvements
* 🛠️ **Self-Healing Agent** — Automatically fixes issues within configured permission levels

**Self-Healing Modes:**
* Monitor — Watch only, log everything
* Suggest — Recommend fixes, you approve
* Auto-Patch — Safe CSS/JS/template fixes applied automatically
* Auto-Full — Rollback settings, disable conflicting plugins

= How It Works =

1. **Collect signals** — GA4 goals, WooCommerce orders, checkout UX telemetry
2. **Analyse** — LLM agents process signals and identify patterns
3. **Experiment** — Auto-create A/B tests on checkout fields, layout, and flow
4. **Improve** — Apply winning variants, suggest further optimisations
5. **Heal** — Detect and fix checkout issues before they cost sales

== Installation ==

1. Upload the `woo-agentic-checkout` folder to `/wp-content/plugins/`
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Go to WooCommerce → Agentic Checkout 🍌
4. Configure your LLM provider (OpenAI, Anthropic, or local Ollama)
5. Set your self-healing permission level
6. Done! Agents will begin on their scheduled cron runs.

== Frequently Asked Questions ==

= Do I need an LLM API key? =

Yes, for full agent functionality. OpenAI (GPT-4o) is recommended. You can also use a local Ollama instance for free, privacy-preserving operation.

= Will this break my checkout? =

Start in **Suggest** mode. The plugin will never auto-apply changes without your approval. Once you've reviewed a few suggestions and built trust, you can upgrade to Auto-Patch or Auto-Full.

= Does this work with other WooCommerce plugins? =

Yes. The plugin hooks into standard WooCommerce filters. It's designed to coexist with other checkout plugins. A/B tests create temporary overrides that don't modify core plugin files.

= Can I run A/B tests manually? =

Yes — create experiments directly from the Experiments tab. Or let the AB Optimizer agent propose and create them automatically.

== Changelog ==

= 0.1.0-alpha =
* Initial release — plugin scaffold, agent framework, A/B test manager, LLM client, admin UI
* 5 agent types with cron scheduling
* Bayesian A/B testing engine
* Multi-provider LLM support (OpenAI, Anthropic, Ollama, OpenRouter)
* GA4 Measurement Protocol integration
* Self-healing with 4 permission levels
* Structured JSON output for all LLM interactions
