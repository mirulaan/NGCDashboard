using System.Text.Json.Serialization;

namespace NGCTelemetryAgent.Models;

public class AgentConfig
{
    [JsonPropertyName("ServerUrl")]
    public string ServerUrl { get; set; } = "http://172.16.50.2/api/system-status";

    [JsonPropertyName("PushIntervalSeconds")]
    public int PushIntervalSeconds { get; set; } = 300;

    [JsonPropertyName("BackupScheduleTime")]
    public string BackupScheduleTime { get; set; } = "02:00:00";

    [JsonPropertyName("SftpHost")]
    public string SftpHost { get; set; } = "172.16.207.14";

    [JsonPropertyName("SftpPort")]
    public int SftpPort { get; set; } = 22;

    [JsonPropertyName("SftpUser")]
    public string SftpUser { get; set; } = "svc_otsftp";

    [JsonPropertyName("SftpPass")]
    public string SftpPass { get; set; } = "z#&A2#@VEAq&krt";

    [JsonPropertyName("AutoStart")]
    public bool AutoStart { get; set; } = true;

    [JsonPropertyName("StartMinimized")]
    public bool StartMinimized { get; set; } = true;
}
