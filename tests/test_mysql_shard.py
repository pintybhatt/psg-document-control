import importlib.util
from pathlib import Path
import tempfile
import unittest


SCRIPT = Path(__file__).resolve().parent / "mysql-shard.py"
SPEC = importlib.util.spec_from_file_location("mysql_shard", SCRIPT)
MODULE = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(MODULE)


class MysqlShardTest(unittest.TestCase):
    def test_every_included_file_is_assigned_once(self):
        files = MODULE.suite_files()
        groups = MODULE.partitions(files, 4)
        self.assertEqual(set(files), set().union(*map(set, groups)))
        self.assertEqual(len(files), sum(map(len, groups)))
        self.assertTrue(all(groups))
        self.assertNotIn("NumberingConcurrencyTest.php", [file.name for file in files])

    def test_slow_mysql_concurrency_file_runs_in_its_own_shard(self):
        files = MODULE.suite_files()
        groups = MODULE.partitions(files, 4)
        self.assertEqual(["MySqlNumberingConcurrencyTest.php"], [file.name for file in groups[3]])
        self.assertFalse(any(file.name == "MySqlNumberingConcurrencyTest.php" for group in groups[:3] for file in group))

    def test_each_generated_configuration_has_only_its_shard_files(self):
        import xml.etree.ElementTree as ET

        with tempfile.TemporaryDirectory() as directory:
            for shard in range(1, 5):
                output = Path(directory) / f"shard-{shard}.xml"
                files = MODULE.write_configuration(shard, 4, output)
                config = ET.parse(output).getroot()
                selected = [Path(element.text) for element in config.findall("./testsuites/testsuite/file")]
                self.assertEqual(files, selected)
                self.assertTrue(Path(config.get("bootstrap")).is_file())


if __name__ == "__main__":
    unittest.main()
