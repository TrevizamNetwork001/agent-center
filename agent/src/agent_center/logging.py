import json
import logging
import logging.handlers
import queue


class SafeFormatter(logging.Formatter):
    def format(self, record):
        # Only allow predefined event identifiers; ignore args, exceptions and request data.
        event = (
            record.msg
            if isinstance(record.msg, str)
            and record.msg
            in {"online", "poll_unavailable", "quarantined", "stopped", "startup_failed"}
            else "agent_event"
        )
        return json.dumps({"event": event, "level": record.levelname})


def configure():
    q = queue.Queue(maxsize=256)

    class NonBlocking(logging.handlers.QueueHandler):
        def enqueue(self, record):
            try:
                self.queue.put_nowait(record)
            except queue.Full:
                pass

    handler = logging.StreamHandler()
    handler.setFormatter(SafeFormatter())
    listener = logging.handlers.QueueListener(q, handler)
    logger = logging.getLogger("agent_center")
    logger.handlers[:] = [NonBlocking(q)]
    logger.setLevel(logging.INFO)
    listener.start()
    return logger, listener
