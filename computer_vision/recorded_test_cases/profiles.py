"""Video and bay assignments for the recorded zone tests."""

from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
VIDEO = ROOT / "computer_vision/simulation_videos"
REGIONS = Path(__file__).resolve().parent / "regions"

ZONES = {
    "A": {
        "car": "XPANDER", "slot": "A-06", "camera": "simulation_zone_a",
        "baseline": VIDEO / "ZONE A SNAPSHOT.mp4",
        "entry": VIDEO / "ENTRANCE_XPANDER.mov", "exit": VIDEO / "EXIT_XPANDER.mov",
        "parking": VIDEO / "PARK_XPANDER-ZONEA-06v1.mp4",
        "departure": VIDEO / "EXITPARK_XPANDER-ZONEA-06v1.mp4",
    },
    "B": {
        "car": "XPANDER", "slot": "B-05", "camera": "simulation_zone_b",
        "baseline": VIDEO / "ZONE B SNAPSHOT.mp4",
        "entry": VIDEO / "ENTRANCE_XPANDER.mov", "exit": VIDEO / "EXIT_XPANDER.mov",
        "parking": VIDEO / "PARK_XPANDER-ZONEB-05V1.mp4",
        "departure": VIDEO / "EXITPARK_XPANDER-ZONEB-05v1.mp4",
    },
    "C": {
        "car": "XPANDER", "slot": "C-01", "camera": "simulation_zone_c",
        "baseline": VIDEO / "ZONE C SNAPSHOT.mp4",
        "entry": VIDEO / "ENTRANCE_XPANDER.mov", "exit": VIDEO / "EXIT_XPANDER.mov",
        "parking": VIDEO / "PARK_XPANDER-ZONEC-01v1.mp4",
        "departure": VIDEO / "EXITPark_XPANDER-ZONEC-01v1.mp4",
    },
    "D": {
        "car": "FD", "slot": "D-02", "camera": "simulation_zone_d",
        "baseline": VIDEO / "ZONE D SNAPSHOT.mp4",
        "entry": VIDEO / "ENTRANCE_FD.mov", "exit": VIDEO / "EXIT_FD.mov",
        "parking": VIDEO / "PARK FD ZONE D-01.mp4",
        "departure": VIDEO / "EXIT FD ZONE D-01.mp4",
    },
    "E": {
        "car": "FD", "slot": "E-01", "camera": "simulation_zone_e",
        "baseline": VIDEO / "ZONE E SNAPSHOT.mp4",
        "entry": VIDEO / "ENTRANCE_FD.mov", "exit": VIDEO / "EXIT_FD.mov",
        "parking": VIDEO / "PARK FD ZONE E-01.mp4",
        "departure": VIDEO / "EXIT FD ZONE E-01.mp4",
    },
}

CASES = ("grace", "normal_fare", "reservation", "reserved_slot", "tenant")


def profile(zone, case):
    zone = zone.upper()
    if zone not in ZONES or case not in CASES and not (zone == "C" and case == "enforcement"):
        raise ValueError(f"No recorded test profile for zone {zone}, case {case}")
    result = dict(ZONES[zone])
    result.update({
        "case": case,
        "regions": REGIONS / f"zone_{zone.lower()}.json",
        "scenario_prefix": f"sim_{case}_{zone.lower()}",
    })
    if case == "enforcement":
        result["violation_camera"] = "simulation_zone_c_violation"
        result["violation_regions"] = REGIONS / "zone_c_violation.json"
        result["violation"] = VIDEO / "VIOLATION_XPANDER-ZONECv1.mp4"
        result["scenario_prefix"] = "sim_enforcement_zone_c"
    return result
