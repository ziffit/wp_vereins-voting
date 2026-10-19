# [0] Allgemeine Prinzipien & Architektur

## Plugin-Identität

- **Plugin-Slug / Name:** `vereins-voting`

## Styling-Prinzip

Kein Tailwind oder fremde CSS-Frameworks. Ausschließlich minimales,
semantisches Basis-CSS für Formulare und Tabellen, damit sich das Plugin
nahtlos in jedes WordPress-Theme einfügt.

## Sicherheits-Architektur

Strikte Trennung von **Identitätsprüfung/Wahlberechtigung**
(`wp_voting_voters`) und **anonymer Stimmurne** (`wp_voting_ballots`).

- Keine Foreign Keys zwischen den Tabellen.
- Speicherung von PINs ausschließlich als sichere Hashes (`password_hash`).
