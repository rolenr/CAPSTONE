"""Shared command-line parsing and validation for recorded-video scenarios."""

import argparse
import os


def parse_video_args(description, video_options):
    """Parse named video options and return absolute paths to existing files.

    ``video_options`` maps argument names (without ``--``) to
    ``(help_text, default_path)`` pairs.
    """
    parser = argparse.ArgumentParser(
        description=description,
        formatter_class=argparse.ArgumentDefaultsHelpFormatter,
    )
    for name, (help_text, default_path) in video_options.items():
        parser.add_argument(
            "--" + name.replace("_", "-"),
            dest=name,
            metavar="PATH",
            default=default_path,
            help=help_text,
        )

    args = parser.parse_args()
    resolved = {}
    for name in video_options:
        path = os.path.abspath(os.path.expanduser(getattr(args, name)))
        if not os.path.isfile(path):
            option = "--" + name.replace("_", "-")
            parser.error(
                "{} points to a missing file: {}. Provide an existing recorded "
                "video with {} PATH.".format(option, path, option)
            )
        resolved[name] = path
    return resolved
