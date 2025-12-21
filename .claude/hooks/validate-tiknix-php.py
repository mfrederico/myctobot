#!/usr/bin/env python3
"""
Tiknix PHP Code Validator Hook

Validates PHP code against Tiknix/RedBeanPHP/FlightPHP coding standards:
1. Bean property names should use camelCase (not underscore_case)
2. Table names should use camelCase (not underscore_case)
3. R::exec should almost NEVER be used - only in extreme situations
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


def validate_php_code(content):
    """Run all validations on PHP content."""
    all_issues = []

    # Skip if not PHP
    if '<?php' not in content and '<?=' not in content:
        # Check if it contains PHP-like RedBean code even without <?php tag
        if 'R::' not in content:
            return []

    all_issues.extend(find_underscore_properties(content))
    all_issues.extend(find_underscore_table_names(content))
    all_issues.extend(find_exec_usage(content))

    return all_issues


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
        issues = validate_php_code(content)

        if issues:
            # Format feedback message
            feedback = "TIKNIX CODE STANDARDS VIOLATION:\n\n"
            for i, issue in enumerate(issues, 1):
                feedback += f"{i}. {issue}\n"
            feedback += "\nSee CLAUDE.md for Tiknix coding standards.\n"
            feedback += "RedBeanPHP docs: https://redbeanphp.com/"

            # Return JSON with block decision
            output = {
                "decision": "block",
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
