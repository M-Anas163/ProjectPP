from pathlib import Path
import sys


PROJECT_ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(PROJECT_ROOT))

import db.models
from db.database import Base, engine
from sqlalchemy import inspect, text


def main() -> None:
    dialect = engine.dialect.name
    migrations = sorted(
        (PROJECT_ROOT / "db" / "migrations").glob(f"*_{dialect}.sql")
    )
    if not migrations:
        raise RuntimeError(f"No migrations found for database dialect {dialect!r}")

    database_is_fresh = not inspect(engine).has_table("orders")
    if database_is_fresh:
        Base.metadata.create_all(bind=engine)

    with engine.begin() as connection:
        connection.execute(
            text(
                "CREATE TABLE IF NOT EXISTS schema_migrations ("
                "name VARCHAR(255) PRIMARY KEY, "
                "applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP"
                ")"
            )
        )
        applied = set(
            connection.execute(text("SELECT name FROM schema_migrations")).scalars()
        )

        for migration in migrations:
            if migration.name in applied:
                continue
            if not database_is_fresh:
                sql = migration.read_text(encoding="utf-8")
                for statement in sql.split("-- migrate:split"):
                    statement = statement.strip()
                    if statement:
                        connection.exec_driver_sql(statement)
            connection.execute(
                text("INSERT INTO schema_migrations (name) VALUES (:name)"),
                {"name": migration.name},
            )
            print(f"Applied {migration.name}")


if __name__ == "__main__":
    main()
