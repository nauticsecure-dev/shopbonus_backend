# Frank backend continuation — 2026-10-31

Source: Downloads `MS0DLCAX - shopbonus (3).json`, active Frank list; reviewer comments compared with the actual code. Frontend implementation is outside this repository's assignment. List placement and prior DONE labels are not acceptance evidence.

| Card | Backend work / acceptance still open | Separate Hassan / senior work |
| --- | --- | --- |
| [#19](https://trello.com/c/QBffQWek) | D-103 points checkout implemented and focused SQLite/MySQL verified; purchase identity, selection freshness and missing-policy tracking repaired and focused SQLite/MySQL verified; configurable earning rules still need an explicit rate/rounding policy | Consumer UI; native network acceptance |
| [#20](https://trello.com/c/KPfe6qGp) | D-103 points checkout implemented and verified; existing payment/refund focused MySQL tests pass; review longer-lived uncertain payment recovery | Checkout/My Vouchers UI; real Mollie TEST acceptance |
| [#21](https://trello.com/c/IsmQwCbL) | Validate committed review fixes and complete remaining security/operations evidence | Cockpit UI and deployed monitoring |
| [#22](https://trello.com/c/SxfIv4Ir) | Release inventory and independently executed backend evidence; deployment remains unverified | Frontend sample APIs/charts; ten native-provider acceptance runs; release approval |
| [#23](https://trello.com/c/rclMXYPz) | Remaining native connector capabilities require verified provider contracts; privacy/financial decisions remain explicit | Native install artifacts/storefront integration; actual provider runs |
| [#26](https://trello.com/c/aWBrCkQK) | Validate committed planner/FAQ/balance/localization fixes; provider cost remains an estimate | Pending conversation recovery, reconnect handling, real configured model acceptance |
| [#29](https://trello.com/c/WcPlsK8w) | Confirm configured frontend paths against actual router; validate template context and delivery evidence | Workspace selection, reconnect/shared subscriptions, template UI |
| [#30](https://trello.com/c/6xHzjOwY) | Backup cadence configurable against an approved target; prove restored evidence; formal revenue recognition is a separate policy | Offsite/key recovery and timed infrastructure restore drill; operations UI |
| [#36](https://trello.com/c/Fzt8yc0J) | Backend defects and complete current inventory; 175 PDF v2 checks need the actual attachment and independent evidence, never inferred execution | Hassan independently executes and retests staging build; native subreports |
| [#37](https://trello.com/c/KgcTACuB) | CI report artifacts/import/discovery integration; evidence mapping and executable HTTP suite; validate provenance fixes | Quality Center/portal UI, hosting, staging execution and independent retests |

D-089 remains network approval before spendability. D-053 remains EUR credit plus configured Mollie shortfall. D-103 permits explicitly priced integer-points purchases without conversion or monetary shortfall. No card is certified wholly Done by this inventory. Status will be updated only after implementation and executed evidence.
