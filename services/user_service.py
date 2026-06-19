from fastapi import HTTPException, status
from sqlalchemy import select
from sqlalchemy.orm import Session

from db.models import User
from services.security_service import hash_password
from services.transaction_service import transactional


@transactional
def create_user(*, email: str, password: str, db: Session) -> dict:
    existing = db.scalar(select(User).where(User.email == email))
    if existing is not None:
        raise HTTPException(
            status_code=status.HTTP_409_CONFLICT,
            detail="Email already exists",
        )

    user = User(email=email, password_hash=hash_password(password))
    db.add(user)
    db.flush()
    db.refresh(user)
    return {"id": user.id, "email": user.email, "created_at": user.created_at}
