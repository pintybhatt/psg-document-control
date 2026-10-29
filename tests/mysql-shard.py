#!/usr/bin/env python3
"""Split the MySQL PHPUnit file suite into deterministic, disjoint CI jobs."""

import argparse
from pathlib import Path
import xml.etree.ElementTree as ET

ROOT = Path(__file__).resolve().parent.parent
SOURCE = ROOT / "phpunit.mysql.xml"
SLOW_MYSQL_FILE = "MySqlNumberingConcurrencyTest.php"


def suite_files(root=ROOT, configuration=SOURCE):
    tree = ET.parse(configuration)
    suite = tree.find("./testsuites/testsuite")
    if suite is None:
        raise ValueError("MySQL PHPUnit test suite is missing")
    excluded = {(root / element.text).resolve() for element in suite.findall("exclude")}
    files = set()
    for directory in suite.findall("directory"):
        files.update((root / directory.text).rglob("*Test.php"))
    for entry in suite.findall("file"):
        files.add(root / entry.text)
    return sorted(path.resolve() for path in files if path.resolve() not in excluded)


def partitions(files, total):
    """Reserve a shard for slow MySQL concurrency tests; balance the rest."""
    bins = [[] for _ in range(total)]
    weights = [0] * total
    slow_files = [path for path in files if path.name == SLOW_MYSQL_FILE]
    if total > 1 and slow_files:
        bins[-1].extend(slow_files)
        files = [path for path in files if path.name != SLOW_MYSQL_FILE]
        available = range(total - 1)
    else:
        available = range(total)
    for path in sorted(files, key=lambda item: (-item.stat().st_size, str(item))):
        shard = min(available, key=lambda index: (weights[index], index))
        bins[shard].append(path)
        weights[shard] += path.stat().st_size
    return [sorted(group) for group in bins]


def write_configuration(shard, total, output):
    files = suite_files()
    if not files or total > len(files):
        raise ValueError("The number of shards must not exceed the number of test files")
    tree = ET.parse(SOURCE)
    root = tree.getroot()
    root.set("bootstrap", str((ROOT / root.get("bootstrap")).resolve()))
    root.set("cacheDirectory", str((ROOT / root.get("cacheDirectory")).resolve()))
    for directory in root.findall("./source/include/directory"):
        directory.text = str((ROOT / directory.text).resolve())
    suites = root.find("testsuites")
    suites.clear()
    suite = ET.SubElement(suites, "testsuite", {"name": f"MySQL shard {shard}/{total}"})
    selected = partitions(files, total)[shard - 1]
    for file in selected:
        ET.SubElement(suite, "file").text = str(file)
    output.parent.mkdir(parents=True, exist_ok=True)
    tree.write(output, encoding="UTF-8", xml_declaration=True)
    return selected


if __name__ == "__main__":
    parser = argparse.ArgumentParser()
    parser.add_argument("--shard", type=int, required=True)
    parser.add_argument("--total", type=int, required=True)
    parser.add_argument("--output", type=Path, required=True)
    args = parser.parse_args()
    if args.total < 1 or not 1 <= args.shard <= args.total:
        parser.error("shard must be between 1 and total")
    selected = write_configuration(args.shard, args.total, args.output)
    print(f"Shard {args.shard}/{args.total}: {len(selected)} test files")
