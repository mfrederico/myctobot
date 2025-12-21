#!/usr/bin/env python3
"""
Tiknix PHP Code Validator Hook

Validates PHP code against Tiknix/RedBeanPHP/FlightPHP coding standards:
1. Bean type names must be all lowercase (no underscores) for R::dispense
2. R::exec should almost NEVER be used - only in extreme situations
3. Prefer RedBeanPHP associations (ownBeanList/sharedBeanList) over manual FK management
"""

import json
import sys
import re


def find_underscore_properties(content):
    """
    Find bean properties using underscore_case instead of camelCase.

    Note: RedBeanPHP accepts both snake_case and camelCase for properties.
    We PREFER camelCase but don't block on snake_case since both work.
    This returns an empty list - snake_case is allowed but camelCase is preferred.
    """
    # Both conventions work in RedBeanPHP, so we don't block on this
    # Preference for camelCase is documented in CLAUDE.md
    return []


def find_underscore_table_names(content):
    """
    Find R::dispense with invalid bean type names - these WILL FAIL at runtime!

    CRITICAL: R::dispense() bean type names must be:
    - All lowercase (a-z)
    - Only alphanumeric (no underscores, no uppercase)

    The error will be: "Invalid bean type: table_name"

    For R::findOne, R::find, R::load - the bean type is more flexible
    because RedBeanPHP converts it. But R::dispense is strict.

    Must use all lowercase for R::dispense (e.g., 'enterprisesettings' not 'enterprise_settings' or 'enterpriseSettings')
    """
    issues = []

    # Match R::dispense with any table name
    pattern = r"R::dispense\s*\(\s*['\"]([a-zA-Z0-9_]+)['\"]"

    for match in re.finditer(pattern, content):
        table_name = match.group(1)

        # Check for underscores
        if '_' in table_name:
            # Convert to suggested lowercase
            lowercase = table_name.replace('_', '').lower()
            issues.append(
                f"R::dispense('{table_name}') will FAIL! RedBeanPHP doesn't allow underscores in dispense(). "
                f"Use R::dispense('{lowercase}') instead."
            )
        # Check for uppercase letters
        elif table_name != table_name.lower():
            lowercase = table_name.lower()
            issues.append(
                f"R::dispense('{table_name}') will FAIL! RedBeanPHP requires all lowercase bean types in dispense(). "
                f"Use R::dispense('{lowercase}') instead."
            )

    return issues


def find_exec_usage(content):
    """Find problematic use of R::exec and flag it for review."""
    issues = []

    # Match R::exec with any SQL statement
    pattern = r"R::exec\s*\(\s*['\"]([^'\"]+)['\"]"

    for match in re.finditer(pattern, content):
        sql = match.group(1).strip()
        sql_upper = sql.upper()

        # DDL operations are OK - these can't be done with beans
        if sql_upper.startswith('CREATE ') or sql_upper.startswith('ALTER ') or sql_upper.startswith('DROP '):
            continue  # DDL is acceptable

        # Check what type of operation it is
        if sql_upper.startswith('INSERT'):
            issues.append(f"R::exec() used for INSERT. This bypasses FUSE models! Use Bean::dispense() + Bean::store() instead.")
        elif sql_upper.startswith('UPDATE'):
            # Check if it's a simple update that should use beans
            if 'WHERE' in sql_upper and ('= ?' in sql or '=?' in sql):
                # Check if it's NOT a complex operation (increment, bulk, etc.)
                if '+ 1' not in sql and '- 1' not in sql and 'NOW()' not in sql_upper:
                    issues.append(f"R::exec() used for UPDATE. This bypasses FUSE models! Use Bean::load() + Bean::store() instead.")
                else:
                    issues.append(f"R::exec() for UPDATE detected. Verify this is truly necessary and cannot be done with beans.")
            else:
                issues.append(f"R::exec() for UPDATE detected. Verify this is truly necessary and cannot be done with beans.")
        elif sql_upper.startswith('DELETE'):
            issues.append(f"R::exec() used for DELETE. This bypasses FUSE models! Use Bean::trash() instead.")
        else:
            issues.append(f"R::exec() detected. R::exec should ONLY be used in extreme situations. Can this use bean methods instead?")

    return issues


def find_manual_fk_assignments(content):
    """
    Detect manual foreign key assignments and suggest using associations instead.

    Patterns to detect:
    - $bean->parent_id = $id  (manual FK assignment)
    - $bean->board_id = $boardId
    - Bean::find('child', 'parent_id = ?', [$id])

    These should use ownBeanList or sharedBeanList associations instead.
    """
    issues = []

    # Common FK column patterns (table_id or tablename_id)
    fk_patterns = [
        r'\$\w+->(\w+_id)\s*=',  # $bean->parent_id =
        r'\$\w+->(\w+Id)\s*=',   # $bean->parentId =
    ]

    # Known FK columns that should use associations
    known_fks = [
        'board_id', 'boardId', 'jiraboards_id',
        'job_id', 'jobId', 'aidevjobs_id',
        'repo_id', 'repoId', 'repoconnections_id',
        'member_id', 'memberId',
        'parent_id', 'parentId',
    ]

    for pattern in fk_patterns:
        for match in re.finditer(pattern, content):
            fk_column = match.group(1)
            # Check if this looks like a FK column
            if fk_column.lower() in [fk.lower() for fk in known_fks] or fk_column.endswith('_id') or fk_column.endswith('Id'):
                # Extract parent table name from FK
                if fk_column.endswith('_id'):
                    parent = fk_column[:-3]  # Remove _id
                elif fk_column.endswith('Id'):
                    parent = fk_column[:-2]  # Remove Id
                else:
                    parent = fk_column

                issues.append(
                    f"Manual FK assignment detected: ${fk_column}. "
                    f"Consider using RedBeanPHP associations instead: "
                    f"$parent->ownChildList[] = $child (auto-sets FK, lazy loading, cascade delete with xown). "
                    f"See CLAUDE.md for examples."
                )
                break  # Only report once per file to avoid spam

    # Detect find queries with FK WHERE clauses
    fk_find_pattern = r"(?:R|Bean)::(?:find|findOne|findAll)\s*\(\s*['\"](\w+)['\"],\s*['\"](\w+_id)\s*="

    for match in re.finditer(fk_find_pattern, content):
        child_table = match.group(1)
        fk_column = match.group(2)

        # Extract parent table from FK
        parent = fk_column[:-3] if fk_column.endswith('_id') else fk_column

        issues.append(
            f"Manual FK query detected: Bean::find('{child_table}', '{fk_column} = ?'). "
            f"Consider using associations: $parent->own{child_table.title()}List (lazy loads, auto-cached). "
            f"See CLAUDE.md for examples."
        )
        break  # Only report once

    return issues


def validate_php_code(content):
    """
    Run all validations on PHP content.

    Returns tuple: (blocking_issues, warning_issues)
    - blocking_issues: Will block the operation (critical errors)
    - warning_issues: Will warn but allow (suggestions/best practices)
    """
    blocking_issues = []
    warning_issues = []

    # Skip if not PHP
    if '<?php' not in content and '<?=' not in content:
        # Check if it contains PHP-like RedBean code even without <?php tag
        if 'R::' not in content and 'Bean::' not in content:
            return [], []

    # Blocking issues - these will cause runtime errors
    blocking_issues.extend(find_underscore_properties(content))
    blocking_issues.extend(find_underscore_table_names(content))

    # Warning issues - suggestions for better practices
    warning_issues.extend(find_exec_usage(content))
    warning_issues.extend(find_manual_fk_assignments(content))

    return blocking_issues, warning_issues


def main():
    try:
        # Read input from stdin (JSON format from Claude Code)
        input_data = json.load(sys.stdin)

        tool_name = input_data.get('tool_name', '')
        tool_input = input_data.get('tool_input', {})

        # Only validate Write and Edit operations
        if tool_name not in ['Write', 'Edit']:
            sys.exit(0)

        # Get file path and content
        file_path = tool_input.get('file_path', '')

        # Only validate PHP files
        if not file_path.endswith('.php'):
            sys.exit(0)

        # Get the content being written/edited
        if tool_name == 'Write':
            content = tool_input.get('content', '')
        elif tool_name == 'Edit':
            content = tool_input.get('new_string', '')
        else:
            sys.exit(0)

        # Run validations
        blocking_issues, warning_issues = validate_php_code(content)

        # Blocking issues - will prevent the operation
        if blocking_issues:
            feedback = "TIKNIX CODE STANDARDS VIOLATION (BLOCKING):\n\n"
            for i, issue in enumerate(blocking_issues, 1):
                feedback += f"{i}. {issue}\n"
            feedback += "\nThese issues will cause runtime errors. Fix before proceeding.\n"
            feedback += "See CLAUDE.md for Tiknix coding standards."

            output = {
                "decision": "block",
                "reason": feedback
            }
            print(json.dumps(output))
            sys.exit(0)

        # Warning issues - allow but inform
        if warning_issues:
            feedback = "TIKNIX BEST PRACTICES SUGGESTION:\n\n"
            for i, issue in enumerate(warning_issues, 1):
                feedback += f"{i}. {issue}\n"
            feedback += "\nThese are suggestions for better code. Operation allowed.\n"
            feedback += "See CLAUDE.md for RedBeanPHP association patterns."

            # Use "report" decision to show message but allow operation
            # If "report" isn't supported, this will just pass through
            output = {
                "decision": "allow",  # Allow but with message
                "reason": feedback
            }
            print(json.dumps(output))

        sys.exit(0)

    except json.JSONDecodeError:
        # If input isn't valid JSON, just pass through
        sys.exit(0)
    except Exception as e:
        # Log error but don't block
        print(f"Hook error: {e}", file=sys.stderr)
        sys.exit(0)


if __name__ == '__main__':
    main()
